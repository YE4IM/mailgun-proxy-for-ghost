<?php

declare(strict_types=1);

/*
| Ghost 6.78.0 native newsletter sends (including send-test, which Ghost's admin
| API answers with HTTP 204) go through MailgunEmailProvider with
| perRecipientMessageId. mailgun-client.js then posts:
|
|   h:Message-Id = <%recipient.message_id%>
|
| and a per-recipient message_id of `{emailId}.{32 hex}@{sending-domain}` with
| no brackets of its own. The proxy must substitute that token and hand Symfony
| Mailer an IdentificationHeader. Putting the value in Headers::$text makes
| addTextHeader() build an UnstructuredHeader, and Symfony Mime 8.0.8 rejects
| it in Headers::checkHeaderClass before the message reaches the transport.
|
| These tests drive POST /v3/{domain}/messages, the queued listener, and the
| queued mailable through the array transport. No mail leaves the process.
*/

use App\Models\NewsletterRequest;
use App\Models\NewsletterRequestDelivery;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Header\IdentificationHeader;
use Symfony\Component\Mime\Header\MailboxListHeader;
use Symfony\Component\Mime\Header\UnstructuredHeader;
use Tests\TestCase;

const GHOST_PREVIEW_MESSAGE_ID = '64f0c7a5e2b3a1d4c5b6a7f8.0123456789abcdef0123456789abcdef@example.com';

test('ghost native preview message id is queued as a single identification header', function (): void {
    $response = postCapturedGhostMessage(ghostNativePreviewInput());

    $response->assertOk();
    expect($response->json('message'))->toBe('Queued. Thank you.')
        ->and($response->json('id'))->toMatch('/^<nr-\d+@example\.com>$/');

    // Acceptance is recorded before the mailable runs, matching the observed
    // split between a successful Ghost/proxy response and the later worker.
    expect(DB::table('jobs')->count())->toBeGreaterThan(0)
        ->and(capturedSentMessages())->toBeEmpty();

    drainCapturedMailQueue();

    $sent = capturedSentMessages()->sole();
    $email = originalEmail($sent);
    $messageId = $email->getHeaders()->get('Message-ID');

    expect($messageId)->toBeInstanceOf(IdentificationHeader::class)
        ->and($messageId->getBodyAsString())->toBe('<'.GHOST_PREVIEW_MESSAGE_ID.'>')
        ->and($sent->getMessageId())->toBe(GHOST_PREVIEW_MESSAGE_ID)
        ->and(messageIdHeaderCount($sent))->toBe(1)
        ->and($email->getHeaders()->get('List-Unsubscribe'))->toBeInstanceOf(UnstructuredHeader::class)
        ->and($email->getHeaders()->get('List-Unsubscribe')->getBody())->toBe('<https://example.test/unsub/preview>')
        ->and($email->getHeaders()->get('Auto-Submitted'))->toBeInstanceOf(UnstructuredHeader::class)
        ->and($email->getHeaders()->get('Auto-Submitted')->getBody())->toBe('auto-generated')
        ->and($email->getHeaders()->get('X-Auto-Response-Suppress'))->toBeInstanceOf(UnstructuredHeader::class)
        ->and($email->getHeaders()->get('X-Auto-Response-Suppress')->getBody())->toBe('OOF, AutoReply')
        ->and($email->getHeaders()->get('X-Proxy-Trace'))->toBeInstanceOf(UnstructuredHeader::class)
        ->and($email->getHeaders()->get('X-Proxy-Trace')->getBody())->toBe('synthetic-preview')
        ->and($email->getHeaders()->get('Reply-To'))->toBeInstanceOf(MailboxListHeader::class)
        ->and($email->getHeaders()->get('Reply-To')->getBodyAsString())->toContain('editor@example.com');

    $headers = unfoldedHeaderBlock($sent);
    expect($headers)->toContain('Message-Id: <'.GHOST_PREVIEW_MESSAGE_ID.'>')
        ->and(substr_count($headers, 'Message-Id:'))->toBe(1)
        ->and(substr_count($headers, 'Message-ID:'))->toBe(0)
        ->and($email->getHtmlBody())->toContain('Hello Preview');

    $delivery = NewsletterRequestDelivery::query()->sole();
    $request = NewsletterRequest::query()->sole();

    expect($request->status->value)->toBe('processed')
        ->and($request->latestAttempt?->error_message)->toBeNull()
        ->and($delivery->recipient)->toBe('preview@example.test')
        ->and($delivery->latest_event)->toBe('accepted')
        ->and($delivery->failed_at)->toBeNull()
        ->and($email->getHeaders()->get('X-Newsletter-Delivery-Id')?->getBodyAsString())->toBe((string) $delivery->id)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

test('message id headers keep one typed id or a generated id', function (mixed $header, ?string $expectedId): void {
    $input = [
        'to' => 'preview@example.test',
        'from' => 'Newsletter <newsletter@example.com>',
        'subject' => 'Preview',
        'text' => 'Synthetic body',
        'h:X-Proxy-Trace' => 'kept-ordinary-header',
    ];

    if ($header !== null) {
        $input['h:Message-Id'] = $header;
    }

    postCapturedGhostMessage($input)->assertOk();
    drainCapturedMailQueue();

    $sent = capturedSentMessages()->sole();
    $email = originalEmail($sent);
    $messageId = $email->getHeaders()->get('Message-ID');

    expect(messageIdHeaderCount($sent))->toBe(1)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(NewsletterRequestDelivery::query()->sole()->latest_event)->toBe('accepted')
        ->and($email->getHeaders()->get('X-Proxy-Trace'))->toBeInstanceOf(UnstructuredHeader::class)
        ->and($email->getHeaders()->get('X-Proxy-Trace')->getBody())->toBe('kept-ordinary-header');

    if ($expectedId !== null) {
        expect($messageId)->toBeInstanceOf(IdentificationHeader::class)
            ->and($messageId->getBodyAsString())->toBe('<'.$expectedId.'>')
            ->and($sent->getMessageId())->toBe($expectedId)
            ->and(unfoldedHeaderBlock($sent))->toContain('Message-Id: <'.$expectedId.'>');

        return;
    }

    // Absent, repeated-distinct, and malformed values must not be copied through
    // as text. Symfony mints exactly one IdentificationHeader instead.
    expect($email->getHeaders()->has('Message-ID'))->toBeFalse()
        ->and($sent->getMessageId())->toMatch('/^[a-f0-9]{32}@example\.com$/')
        ->and(unfoldedHeaderBlock($sent))->toContain('Message-ID: <'.$sent->getMessageId().'>')
        ->and(unfoldedHeaderBlock($sent))->not->toContain('injected@example.net')
        ->and(unfoldedHeaderBlock($sent))->not->toContain('not-a-message-id')
        ->and(unfoldedHeaderBlock($sent))->not->toContain('%recipient.message_id%')
        ->and(unfoldedHeaderBlock($sent))->not->toContain('one@example.com')
        ->and(unfoldedHeaderBlock($sent))->not->toContain('two@example.com');
})->with([
    'bare' => ['bare-id@example.com', 'bare-id@example.com'],
    'bracketed' => ['<bracketed-id@example.com>', 'bracketed-id@example.com'],
    'padded brackets' => ['< padded-id@example.com >', 'padded-id@example.com'],
    'repeated identical' => ['<same-id@example.com> <same-id@example.com>', 'same-id@example.com'],
    'repeated identical comma' => ['<same-id@example.com>, <same-id@example.com>', 'same-id@example.com'],
    'absent' => [null, null],
    'empty' => ['   ', null],
    'empty brackets' => ['<>', null],
    'invalid' => ['not-a-message-id', null],
    'unresolved ghost template' => ['<%recipient.message_id%>', null],
    'repeated distinct' => ['<one@example.com> <two@example.com>', null],
    'repeated json list' => [['<one@example.com>', '<two@example.com>'], null],
    'crlf injection' => ["<good-id@example.com>\r\nBcc: injected@example.net", null],
]);

/**
 * @param  array<string, mixed>  $input
 */
function postCapturedGhostMessage(array $input): TestResponse
{
    config()->set('services.mailgun.key', 'test-mailgun-key');
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'array');
    config()->set('services.outbox.provider', 'array');

    /** @var TestCase $case */
    $case = test();

    return $case->withHeaders([
        'Authorization' => 'Basic '.base64_encode('api:test-mailgun-key'),
    ])->post(route('mailgun.messages', ['domain' => 'example.com']), $input);
}

function drainCapturedMailQueue(): void
{
    Artisan::call('queue:work', [
        '--stop-when-empty' => true,
        '--sleep' => 0,
        '--tries' => 1,
        '--timeout' => 30,
    ]);
}

/**
 * @return Collection<int, SentMessage>
 */
function capturedSentMessages(): Collection
{
    $transport = resolve('mail.manager')->mailer()->getSymfonyTransport();

    throw_unless($transport instanceof ArrayTransport, RuntimeException::class, 'Expected the array mail transport.');

    /** @var Collection<int, SentMessage> $messages */
    $messages = $transport->messages();

    return $messages;
}

function originalEmail(SentMessage $sent): Email
{
    $message = $sent->getOriginalMessage();

    throw_unless($message instanceof Email, RuntimeException::class, 'Captured mail is not a Symfony Email.');

    return $message;
}

function messageIdHeaderCount(SentMessage $sent): int
{
    preg_match_all('/^message-id:/mi', unfoldedHeaderBlock($sent), $matches);

    return count($matches[0]);
}

function unfoldedHeaderBlock(SentMessage $sent): string
{
    $raw = $sent->getMessage()->toString();
    $header = explode("\r\n\r\n", $raw, 2)[0];

    return (string) preg_replace("/\r\n[ \t]+/", '', $header);
}

/**
 * Ghost 6.78 MailgunEmailProvider + mailgun-client.js payload for one test recipient.
 *
 * @return array<string, mixed>
 */
function ghostNativePreviewInput(): array
{
    return [
        'to' => ['preview@example.test'],
        'from' => 'Ghost Preview <newsletter@example.com>',
        'subject' => 'Native preview',
        'html' => '<p>Hello %recipient.name%</p>',
        'text' => 'Hello %recipient.name%',
        'recipient-variables' => json_encode([
            'preview@example.test' => [
                'name' => 'Preview',
                'list_unsubscribe' => 'https://example.test/unsub/preview',
                'message_id' => GHOST_PREVIEW_MESSAGE_ID,
            ],
        ], JSON_THROW_ON_ERROR),
        'h:Reply-To' => 'Editor <editor@example.com>',
        'h:Sender' => 'Ghost Preview <newsletter@example.com>',
        'h:Auto-Submitted' => 'auto-generated',
        'h:X-Auto-Response-Suppress' => 'OOF, AutoReply',
        'h:List-Unsubscribe' => '<%recipient.list_unsubscribe%>, <%tag_unsubscribe_email%>',
        'h:List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        'h:X-Proxy-Trace' => 'synthetic-preview',
        'h:Message-Id' => '<%recipient.message_id%>',
        'v:email-id' => 'ghost-preview-email',
    ];
}

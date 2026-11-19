<?php

declare(strict_types=1);

namespace App\Mail;

use App\Actions\Newsletter\RecordDeliveryEvent;
use App\Data\Newsletter\NewsletterRecipientData;
use App\Data\Newsletter\NewsletterSendRequestData;
use App\Models\NewsletterRequestDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Address as SymfonyAddress;
use Symfony\Component\Mime\Exception\RfcComplianceException;

class GhostNewsletter extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public NewsletterSendRequestData $request,
        public NewsletterRecipientData $recipient,
        public int $deliveryId,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->request ? $this->parseAddress($this->request->message->from) : new Address('noreply@example.com', 'Newsletter'),
            replyTo: $this->replyToAddresses(),
            subject: $this->request?->message?->subject ?? 'Newsletter',
        );
    }

    public function headers(): Headers
    {
        $headers = $this->request?->headers ?? [];

        // Symfony Mime 8 accepts Message-ID only as an IdentificationHeader.
        // Laravel's text bag always becomes an UnstructuredHeader, which the
        // queue worker rejects before the message reaches the transport.
        // A missing or unusable value stays null so Symfony mints exactly one id.
        return new Headers(
            messageId: $this->messageIdentifier($headers['message_id'] ?? null),
            text: collect($headers)
                ->merge([
                    'x_newsletter_delivery_id' => (string) $this->deliveryId,
                ])
                ->reject(fn (string $value, string $key): bool => in_array($key, ['reply_to', 'sender', 'message_id'], true))
                ->mapWithKeys(fn (string $value, string $key): array => [$this->headerName($key) => $value])
                ->all(),
        );
    }

    public function content(): Content
    {
        $html = $this->request?->message?->html;
        $text = $this->request?->message?->text;

        return new Content(
            htmlString: $html ?? nl2br(e($text ?? '')),
        );
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return config('services.outbox.provider') === 'resend'
            ? [new RateLimited('resend-outbox')]
            : [];
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }

    public function failed(\Throwable $throwable): void
    {
        $delivery = NewsletterRequestDelivery::query()->find($this->deliveryId);

        if ($delivery === null) {
            return;
        }

        resolve(RecordDeliveryEvent::class)->handle(
            delivery: $delivery,
            event: 'failed',
            providerEvent: 'message.failed',
            occurredAt: CarbonImmutable::now(),
            payload: [
                'error_message' => $throwable->getMessage(),
                'error_class' => $throwable::class,
                'trace' => $throwable->getTraceAsString(),
            ],
        );
    }

    /**
     * @return array<int, Address>
     */
    private function replyToAddresses(): array
    {
        $replyTo = $this->request->headers['reply_to'] ?? null;

        if (! is_string($replyTo) || $replyTo === '') {
            return [];
        }

        return [$this->parseAddress($replyTo)];
    }

    private function parseAddress(string $value): Address
    {
        if (preg_match('/^(.+?)\s*<([^>]+)>$/', $value, $matches) !== 1) {
            return new Address($value);
        }

        return new Address(
            trim($matches[2]),
            trim($matches[1], ' "'),
        );
    }

    private function headerName(string $key): string
    {
        return str($key)
            ->replace('_', '-')
            ->title()
            ->value();
    }

    /**
     * Bare msg-id for Laravel's messageId argument, or null when Symfony should generate one.
     *
     * Ghost 6.78 sends `<%recipient.message_id%>`. After substitution that is one
     * bracketed id. Repeated copies of that same id collapse to one header.
     * Distinct, empty, unresolved, or CRLF-bearing values are not copied into
     * the text bag: Message-ID is unique, and a raw newline would be header injection.
     */
    private function messageIdentifier(?string $value): ?string
    {
        if ($value === null || preg_match('/[\r\n\0]/', $value) === 1) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^<\s*([^<>\s]+)\s*>$/D', $value, $matches) === 1) {
            return $this->symfonyMessageIdentifier($matches[1]);
        }

        if (preg_match('/^[^<>\s,]+$/D', $value) === 1) {
            return $this->symfonyMessageIdentifier($value);
        }

        $tokens = preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return null;
        }

        $ids = [];

        foreach ($tokens as $token) {
            if (preg_match('/^<([^<>\s]+)>$/D', $token, $matches) === 1) {
                $id = $matches[1];
            } elseif (preg_match('/^[^<>\s]+$/D', $token) === 1) {
                $id = $token;
            } else {
                return null;
            }

            $accepted = $this->symfonyMessageIdentifier($id);

            if ($accepted === null) {
                return null;
            }

            $ids[] = $accepted;
        }

        $ids = array_values(array_unique($ids));

        return count($ids) === 1 ? $ids[0] : null;
    }

    private function symfonyMessageIdentifier(string $id): ?string
    {
        try {
            $address = new SymfonyAddress($id);
        } catch (RfcComplianceException) {
            return null;
        }

        // IdentificationHeader renders the address unchanged only when Symfony
        // accepts it as written. A rewritten id would no longer be Ghost's.
        if ($address->getAddress() !== $id || $address->getEncodedAddress() !== $id) {
            return null;
        }

        return $id;
    }
}

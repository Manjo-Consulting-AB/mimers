<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mejlet App\Support\Notification\EmailChannel skickar för en notis, se issue
 * 32a § Beslut 3–5. Mailet bär notistypen och dess `payload` vidare till
 * markdown-vyn `mail/notification`, som översätter brödtexten ur
 * `lang/{sv,en}/notiser.php`; ämnesraden översätts här i `envelope()`.
 *
 * Nyckelregeln (Beslut 4) är identisk i båda lagren: punkten i `task.due`
 * byts mot understreck och nyckeln läses som `notiser.task_due.*`.
 *
 * `payload` skickas rakt in i `trans()` som ersättningsarray — kanalen lägger
 * inte till, formulerar om eller hittar på fält. En payloadnyckel med `:` i
 * vore en oändlig förvirring för Laravels `:nyckel`-byte; fältnamnen ägs av
 * generatorerna i 34b, som ska hålla sig till bokstäver och understreck.
 */
class NotificationMail extends Mailable
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('notiser.'.str_replace('.', '_', $this->type).'.subject', $this->payload),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.notification',
            with: [
                'type' => $this->type,
                'payload' => $this->payload,
            ],
        );
    }
}

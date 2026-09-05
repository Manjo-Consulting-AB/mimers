<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Notification\UnsubscribeLink;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Mejlet App\Support\Notification\EmailChannel skickar för en notis, se issue
 * 32a § Beslut 3–5 och 32b § Beslut 4–5. Mailet bär notistypen och dess
 * `payload` vidare till markdown-vyn `mail/notification`, som översätter
 * brödtexten ur `lang/{sv,en}/notiser.php`; ämnesraden översätts här i
 * `envelope()`.
 *
 * Sedan 32b känner mailet även mottagaren och bär avregistreringslänken: dels
 * som sidfot i vyn, dels som `List-Unsubscribe`- och
 * `List-Unsubscribe-Post`-headers (Beslut 4). Headrarna får Gmails och Apple
 * Mails egna avanmälningsknapp att synas överst i mejlet — den POSTar den
 * signerade URL:en direkt (One-Click, RFC 8058) utan att någon tryckt på en
 * knapp på en webbsida.
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
    private ?string $unsubscribeUrl = null;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly User $user,
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
                'unsubscribeUrl' => $this->unsubscribeUrl(),
            ],
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => "<{$this->unsubscribeUrl()}>",
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    private function unsubscribeUrl(): string
    {
        // Samma URL i sidfoten och List-Unsubscribe-headern — beräknad en gång
        // så att `expires` aldrig hinner skilja sig mellan de två.
        return $this->unsubscribeUrl ??= app(UnsubscribeLink::class)->for($this->user, $this->type);
    }
}

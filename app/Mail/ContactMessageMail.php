<?php

namespace App\Mail;

use App\Models\User;
use App\Support\Contact\ContactCategory;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;

/**
 * Kontaktformulärets meddelande till supportbrevlådan — M89 ·
 * [[ADR-0096 Kontaktformuläret]] § 3.
 *
 * **Avsändaren är appens egen.** `From` är `MAIL_FROM_ADDRESS`; personens
 * adress står bara i `Reply-To`. En främmande adress i `From` underkänns av
 * DMARC och hamnar i skräpposten — personalen svarar från brevlådan, och
 * `Reply-To` är det som gör svaret till personens tråd.
 *
 * **Ämnet bär miljön utanför produktion.** `[Contact] <etikett> — <namn>`
 * får prefixet `[<miljö>] `, så att ett mejl från staging aldrig förväxlas
 * med ett riktigt ärende.
 *
 * **Kroppen är vanlig text** och ritas av
 * `resources/views/mail/contact-message.blade.php`. Den bär ingen IP-adress
 * och ingen webbläsarsträng: det som behövs för att svara finns, och
 * resten är personuppgifter utan ett syfte.
 *
 * Kontots ULID slås upp av App\Actions\Contact\SendContactMessage och kommer
 * hit färdigt, så mailet självt frågar aldrig databasen.
 */
class ContactMessageMail extends Mailable
{
    public function __construct(
        public readonly string $category,
        public readonly string $name,
        public readonly string $email,
        public readonly string $message,
        public readonly ?User $user,
        public readonly ?string $accountUlid,
        public readonly string $contentLocale,
        public readonly Carbon $sentAt,
    ) {}

    public function envelope(): Envelope
    {
        $subject = '[Contact] '.ContactCategory::label($this->category).' — '.$this->name;
        $environment = (string) app()->environment();

        if ($environment !== 'production') {
            $subject = '['.$environment.'] '.$subject;
        }

        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            replyTo: [new Address($this->email, $this->name)],
            subject: $subject,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.contact-message',
            with: [
                'category' => $this->category,
                'categoryLabel' => ContactCategory::label($this->category),
                'name' => $this->name,
                'email' => $this->email,
                'signedIn' => $this->user !== null,
                'userUlid' => $this->user?->ulid,
                'accountUlid' => $this->accountUlid,
                'locale' => $this->contentLocale,
                'sentAt' => $this->sentAt->copy()->utc()->toIso8601String(),
                'body' => $this->message,
            ],
        );
    }
}

<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Mejlet App\Console\SendsWeeklyDigest skickar en gång i veckan till varje
 * mottagare med väntande `digest`-markerade leveranser, se issue 35 § Beslut
 * 4–5. Till skillnad från NotificationMail bär det MÅNGA notiser i ett mejl —
 * det är därför det är en egen Mailable och inte ett anrop till EmailChannel,
 * som renderar en enda notis per kanal.
 *
 * Varje post i `items` är en notis `type` och dess `payload`, och brödtexten
 * för varje post översätts i markdown-vyn med samma `lang/`-nyckel som ett
 * enskilt mejl skulle använt (`notiser.task_due.line`, 32a § Beslut 4).
 * Omslaget — ämnesrad, hälsning, intro och "fler"-raden — ligger under
 * `notiser.digest.*`.
 *
 * Ämnesradens `:count` är antalet poster som LISTAS i mejlet. När listan har
 * kapats av `notiser.digest.max_items` berättar `moreCount` (texten
 * `notiser.digest.more`) att det finns fler än de som visas — men alla poster
 * som plockats bokförs som skickade ändå (35 § "Att se upp med").
 *
 * Precis som NotificationMail skickas det via `Mail::to($user)->locale(...)`,
 * aldrig som ett queued jobb — se NotificationMail för varför
 * (34a § Beslut 2).
 */
class WeeklyDigestMail extends Mailable
{
    /**
     * @param  list<array{type: string, payload: array<string, mixed>}>  $items
     */
    public function __construct(
        public readonly array $items,
        public readonly int $moreCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('notiser.digest.subject', ['count' => count($this->items)]),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.digest',
            with: [
                'items' => $this->items,
                'moreCount' => $this->moreCount,
            ],
        );
    }
}

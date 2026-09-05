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
 * Ämnesradens `:count` (`totalCount`) är antalet rader som BOKFÖRS i den här
 * sammanfattningen — alla hämtade `pending`-rader, inte bara de `max_items`
 * som ryms i listan. Ett konto med 400 förfallande uppgifter ska få
 * "…: 400 påminnelser" även om mejlet bara listar 50; `more`-texten
 * (`moreCount` = totalen − listade) talar om att det finns fler. Är listan
 * inte kapad är totalen och det listade samma tal.
 *
 * Avregistreringslänk saknas MEDVETET: sammanfattningen är ett
 * icke-transaktionellt massutskick ([[Notiser]] § email_suppression) och
 * behöver en egen avregistreringsdestination plus `List-Unsubscribe`, men
 * 32b:s per-typ-mekanism duger inte — mejlet blandar typer, och att peka på
 * en av dem skulle avregistrera från en del av innehållet. Det kräver en ny
 * rutt och en ny `lang/`-nyckel, som 35 lägger utanför omfånget; se
 * arkitektsvaret på issue 203 och uppföljningsarbetet där. Får inte gå i
 * produktion mot riktiga mottagare innan det finns.
 *
 * Precis som NotificationMail skickas det via `Mail::to($user)->locale(...)`,
 * aldrig som ett queued jobb — se NotificationMail för varför
 * (34a § Beslut 2).
 */
class WeeklyDigestMail extends Mailable
{
    /**
     * @param  list<array{type: string, payload: array<string, mixed>}>  $items
     * @param  int  $moreCount  totalen − listade; visas som `notiser.digest.more`
     * @param  int  $totalCount  antalet rader som bokförs; visas i ämnesraden
     */
    public function __construct(
        public readonly array $items,
        public readonly int $moreCount,
        public readonly int $totalCount,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: trans('notiser.digest.subject', ['count' => $this->totalCount]),
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

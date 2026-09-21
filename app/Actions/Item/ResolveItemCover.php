<?php

namespace App\Actions\Item;

use App\Models\Attachment;
use App\Models\Item;
use Illuminate\Support\Collection;

/**
 * Itemets omslagsbild — regeln som gör valet frivilligt (issue 93 ·
 * [[ADR-0041 Itemets vy]] § Beslut).
 *
 * Ordningen är:
 *
 *   1. den VALDA bilagan, om den finns kvar, hör till itemet och är en bild,
 *   2. annars itemets ÄLDSTA bild,
 *   3. annars ingen bild.
 *
 * Regeln bor HÄR och aldrig i vyn: en vy som själv letar upp den första bilden
 * bland bilagorna är en andra regel som glider ifrån den första, och en mall
 * går inte att pröva. Den ger användaren det hon bad om — finns bara en bild
 * används den — utan att kräva ett val av den som har två och inte bryr sig,
 * och den låter inte itemets ansikte byta skepnad varje gång någon laddar upp
 * ett foto: steg 2 tar den äldsta, inte den nyaste.
 *
 * `images()` är samma regel sedd som LISTA — exakt det urval och den ordning
 * `handle()` väljer ur. Den som behöver itemets bilder själv (redigeringsvyns
 * väljare) läser dem härifrån i stället för att formulera `kind = 'image'` och
 * sorteringen en gång till; två formuleringar av "itemets bilder" glider isär
 * precis som två formuleringar av "vad mottagaren når" (issue 9a § Beslut 8).
 *
 * Mjukraderade bilagor faller bort genom SoftDeletes' globala scope: en vald
 * bilaga som mjukraderats hittas inte i steg 1 och steg 2 gäller, så vyn
 * varken ritar en trasig bild eller ett tomrum ([[ADR-0008 Soft delete och
 * papperskorg]]). Samma sak om pekaren pekar på en bilaga som aldrig var en
 * bild: steg 1 kräver `kind = 'image'` och förbi den kommer hon inte.
 *
 * Ett KONSTANT antal frågor — en för bilderna — oavsett hur många bilagor
 * itemet har: `handle()` frågar `images()` en gång och letar upp valet i
 * svaret, aldrig med en fråga per bilaga.
 */
class ResolveItemCover
{
    /**
     * Itemets omslagsbild, eller null när itemet inte har någon bild alls.
     */
    public function handle(Item $item): ?Attachment
    {
        $images = $this->images($item);

        $chosen = $item->cover_attachment_id === null
            ? null
            : $images->firstWhere('id', $item->cover_attachment_id);

        return $chosen ?? $images->first();
    }

    /**
     * Itemets bilder, ÄLDST först — urvalet och ordningen `handle()` väljer
     * ur.
     *
     * `created_at` stigande med `id` stigande: två bilagor uppladdade samma
     * sekund får ändå en stabil ordning, samma skäl som bilagelistan sorterar
     * fallande på samma par (issue 60 § Beslut 10). Det är spegelbilden av
     * detaljvyns listning med flit — listan visar det senaste först, omslaget
     * väljer det första.
     *
     * @return Collection<int, Attachment>
     */
    public function images(Item $item): Collection
    {
        return $item->attachments()
            ->where('kind', 'image')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}

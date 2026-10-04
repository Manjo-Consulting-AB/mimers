<?php

namespace App\Actions\Attachment;

use App\Actions\Access\ResolveItemScope;
use App\Models\Attachment;
use App\Models\AttachmentOpen;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Användarens senast öppnade filer i en container, nyast först — se
 * [[ADR-0051 Senast öppnade filer]] och [[M24 Desktopdesignen]] § 177.
 * Listan ritas av dokumentfliken i § 178; den här actionen svarar bara på
 * frågan.
 *
 * **Listan filtreras genom ResolveItemScope som allt annat** —
 * App\Actions\Item\ListRecentVisits är förlagan, och åtkomstvillkoret är det
 * samma. En öppningsrad är en rad om förhållandet mellan en person och en
 * bilaga, och den speglar åtkomsten utan att ge den: en bilaga man förlorat
 * åtkomsten till — eller vars item ligger i papperskorgen — försvinner ur
 * listan. Raden står kvar, så en återfådd åtkomst gör den synlig igen, och
 * det är hela skillnaden mot att filtrera vid skrivningen ([[ADR-0051 Senast
 * öppnade filer]] § Beslut).
 *
 * **Containern är given och därför räcker ETT omfångsuppslag.** Till
 * skillnad från ListRecentVisits, som löser omfånget för varje container
 * användaren når, frågar den här bara om den container fliken står i — och
 * en container användaren inte når blir `restricted([])`, alltså ett tomt
 * svar. Urvalet är därför säkert även om anroparen skulle skicka in en
 * container utan att först ha prövat åtkomsten.
 *
 * **Filtret är ett OCH, inte en OR-grupp.** ListRecentVisits bygger en
 * OR-grupp över flera containers och behöver därför en inledande
 * `whereIn('item.id', [])` som skydd; här är `item.container_id` ett
 * villkor som alltid står där, så frågan kan inte bredda sig. Ett begränsat
 * omfång lägger sin itemlista ovanpå, och en tom lista kompilerar till
 * `0 = 1` — "når ingenting", aldrig "når allt".
 *
 * **Ingenting berättar hur många som filtrerats bort.** Svaret är de rader
 * användaren når och ingenting annat: ingen räknare, ingen gråad rad, ingen
 * markering. En lista som sade *"2 dolda"* läckte exakt det omfångsmodellen
 * finns till för att inte läcka.
 *
 * **Frågan ställs mot `attachment_open` och inte mot `attachment`**, som för
 * ListRecentVisits: raden bär `opened_at`, som är det listan sorterar på OCH
 * det fliken skriver ut — en `whereHas` på bilagan hade gett bilagorna men
 * tappat tidsstämpeln. `whereHas('attachment', …)` bär bilagans SoftDeletes
 * och `whereHas('item', …)` itemets, så papperskorgen syns inte i någon av
 * leden; en containerbilaga har `item_id = NULL` och faller därför bort i
 * item-ledet, vilket är rätt — containerns egen bild räknas aldrig som en
 * öppning ([[ADR-0047 Containerns bild]]).
 *
 * **Frågekostnaden är konstant** och växer inte med antalet rader: omfånget
 * i containern (ResolveItemScope, memoiserad per användare och container),
 * öppningsraderna i EN fråga och bilagan med sitt item och sina derivat i
 * TRE. Det är samma
 * form som ListRecentVisits och samma skäl: en fråga per rad är den N+1
 * issue 9a § Att se upp med varnar för.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten är urvalet självt, och
 * anroparen ansvarar för att frågan kommer från en inloggad användare —
 * samma linje som ListFavorites, ListItems och ListRecentVisits.
 */
class ListRecentOpens
{
    /**
     * Antalet rader listan visar — [[ADR-0051 Senast öppnade filer]]
     * § Motivering: raden i bilden är fem kort, och taket på femtio
     * (App\Actions\Attachment\RecordAttachmentOpen) lämnar marginal för de
     * som filtret tar bort.
     */
    public const LIMIT = 5;

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * Användarens senast öppnade filer i containern, nyast först och
     * filtrerade på omfång.
     *
     * `attachment.item` och `attachment.storedFile.derivatives` är
     * eager-laddade: raden visar filens namn, itemets namn och hur länge
     * sedan öppningen var, adressen till filen behöver bilagans ULID, och
     * `hasThumb` prövar om `thumb`-varianten finns (annars ritas ingen
     * miniatyr — `?variant=thumb` mot en bilaga utan derivat svarar 404,
     * issue 61b § Beslut 1). Det är tre frågor, konstanta över antalet rader.
     *
     * @return Collection<int, AttachmentOpen>
     */
    public function handle(User $user, Container $container, int $limit = self::LIMIT): Collection
    {
        $itemIds = $this->resolveItemScope->handle($user, $container)->itemIds();

        return AttachmentOpen::query()
            ->where('attachment_open.user_id', $user->getKey())
            ->whereHas('attachment', function (Builder $query) use ($container, $itemIds) {
                /** @var Builder<Attachment> $query */
                $query->whereHas('item', function (Builder $query) use ($container, $itemIds) {
                    /** @var Builder<Item> $query */
                    $query->where('item.container_id', $container->getKey());

                    // null är "hela containern" och lägger inget villkor;
                    // en tom lista är "når ingenting" och kompilerar till
                    // `0 = 1` — se App\Support\Access\ItemScope.
                    if ($itemIds !== null) {
                        $query->whereIn('item.id', $itemIds);
                    }
                });
            })
            ->with('attachment.item', 'attachment.storedFile.derivatives')
            ->orderByDesc('attachment_open.opened_at')
            ->orderByDesc('attachment_open.id')
            ->limit($limit)
            ->get();
    }
}

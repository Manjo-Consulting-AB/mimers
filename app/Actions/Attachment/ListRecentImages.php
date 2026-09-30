<?php

namespace App\Actions\Attachment;

use App\Actions\Access\ResolveItemScope;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * De senaste bildbilagorna i en container, nyast först — se [[ADR-0050
 * Desktopdesignen]] § 7 och [[M24 Desktopdesignen]] § 173. Panelen *Senaste
 * bilder* på containerns översikt ritar svaret.
 *
 * **Containern är given och därför räcker ETT omfångsuppslag**, precis som i
 * App\Actions\Attachment\ListRecentOpens: en container användaren inte når
 * blir `restricted([])`, alltså ett tomt svar, så urvalet är säkert även om
 * anroparen skulle skicka in en container utan att först ha prövat åtkomsten.
 *
 * **Filtret är ett OCH, inte en OR-grupp.** ListRecentVisits bygger en
 * OR-grupp över flera containers och behöver därför en inledande
 * `whereIn('item.id', [])` som skydd; här är `item.container_id` ett villkor
 * som alltid står där, så frågan kan inte bredda sig. Ett begränsat omfång
 * lägger sin itemlista ovanpå, och en tom lista kompilerar till `0 = 1` —
 * "når ingenting", aldrig "når allt" (App\Support\Access\ItemScope).
 *
 * **Containerns egen bild räknas aldrig** ([[ADR-0047 Containerns bild]]).
 * Den sitter på `container_id` med `item_id = NULL`, och urvalet frågar genom
 * `whereHas('item', …)` — ett led som en containerbilaga inte kan passera,
 * eftersom det inte finns något item att matcha. Samma led bär itemets
 * SoftDeletes, så ett item i papperskorgen faller bort; bilagans eget
 * SoftDeletes gör detsamma för en bilaga i papperskorgen.
 *
 * **Urvalet är `kind = 'image'` och ingenting mer.** Dokument, PDF:er och
 * övrigt finns på dokumentfliken (issue 178) och hör inte i den här panelen —
 * panelen är bildens yta, och en rad som inte går att rita som miniatyr hade
 * tvingat fram en andra form i vyn.
 *
 * **Frågekostnaden är konstant** och växer inte med antalet rader: omfånget i
 * containern (ResolveItemScope, memoiserad per användare och container),
 * bilagorna i EN fråga och sedan itemet, bytena och deras derivat i de
 * eagerladdade relationerna. Det är samma form som ListRecentOpens och samma
 * skäl: en fråga per rad är den N+1 issue 9a § Att se upp med varnar för.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten är urvalet självt, och anroparen
 * ansvarar för att frågan kommer från en inloggad användare — samma linje som
 * ListFavorites, ListItems och ListRecentVisits.
 */
class ListRecentImages
{
    /**
     * Antalet rader panelen visar — [[ADR-0050 Desktopdesignen]] § 7 och
     * `docs/Design/container.jpeg`, som ritar en stor bild och fyra små.
     */
    public const LIMIT = 5;

    public function __construct(private readonly ResolveItemScope $resolveItemScope) {}

    /**
     * De senaste bildbilagorna i containern, nyast först och filtrerade på
     * omfång.
     *
     * `item` och `storedFile.derivatives` är eager-laddade: raden länkar till
     * sitt item och ritar en miniatyr bara när `thumb`-varianten finns — den
     * regeln är `variants` i App\Http\Controllers\ItemController::show och
     * kräver derivaten utan en fråga per bild (issue 61b § Beslut 1).
     *
     * Ordningen är `created_at` fallande med `id` som andrasortering, samma
     * par och samma skäl som bilagelistan: två bilagor uppladdade i samma
     * sekund har samma tidsstämpel, och en lista vars innehåll byter plats
     * mellan två anrop är brus i varje svar.
     *
     * @return Collection<int, Attachment>
     */
    public function handle(User $user, Container $container, int $limit = self::LIMIT): Collection
    {
        $itemIds = $this->resolveItemScope->handle($user, $container)->itemIds();

        return Attachment::query()
            ->where('attachment.kind', 'image')
            ->whereHas('item', function (Builder $query) use ($container, $itemIds) {
                /** @var Builder<Item> $query */
                $query->where('item.container_id', $container->getKey());

                // null är "hela containern" och lägger inget villkor;
                // en tom lista är "når ingenting" och kompilerar till
                // `0 = 1` — se App\Support\Access\ItemScope.
                if ($itemIds !== null) {
                    $query->whereIn('item.id', $itemIds);
                }
            })
            ->with(['item', 'storedFile.derivatives'])
            ->orderByDesc('attachment.created_at')
            ->orderByDesc('attachment.id')
            ->limit($limit)
            ->get();
    }
}

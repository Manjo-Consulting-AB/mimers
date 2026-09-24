<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Database\Eloquent\Collection;

/**
 * Raderna ur händelseloggen i den form historikflikarna ritar dem, se issue 116
 * och [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * **Läsningen och formen är två steg med flit.** Vilka rader som är läsbara
 * avgörs av App\Actions\Audit\ListAuditEvents och ingen annan — den här
 * actionen rör inte urvalet, den översätter det. Skulle formen filtrera hade
 * läsregeln bott på två ställen, och den bor på ett.
 *
 * **Namnen slås upp när raden läses, aldrig när den skrivs.** `audit_log`
 * bär identifierare och inte namn (ADR-0043: loggen överlever det den
 * handlar om), och en namnkolumn hade gjort loggen till ett andra register
 * över det användaren skrivit — ett namn som ändras i går hade stått kvar
 * med sitt gamla värde i dagens rad.
 *
 * Uppslaget är EN fråga för hela listan och inte en per rad: ett itemnamn per
 * rad hade varit den N+1 ADR:en varnar för, och listan är upp till hundra
 * rader lång.
 *
 * **Uppslaget behöver ingen egen omfångsprövning, och det är inte ett
 * förbiseende.** Läsregeln har redan prövat den: en rad som når hit hör till
 * en container användaren äger — där hon ser allt — eller är hennes EGEN rad
 * inom det omfång hon fortfarande når. Ett item som ligger utanför omfånget
 * kan alltså inte förekomma på en rad som kommer hit, och det som faller bort
 * i uppslaget är det som inte längre FINNS. Att pröva omfånget en gång till
 * här vore en andra formulering av samma regel (ADR-0024), och den hade
 * dessutom kunnat svara nej på ett item läsaren faktiskt får se.
 *
 * **Ett item som saknas blir `null`, inte ett påhittat namn.** Det är den
 * neutrala ersättarens plats — *a deleted item* — och den formuleras i vyn ur
 * `lang/en/ui.php`, som varje annan mening användaren läser (ADR-0013).
 * Mjukraderade items faller bort genom SoftDeletes' globala scope: ett item i
 * papperskorgen är raderat för läsaren, och loggen säger *a deleted item* om
 * det. En gallrad rad finns inte alls, och ger samma svar — de två går inte
 * att skilja, och det är med flit (issue 73 § Beslut 6).
 *
 * **Den handlande användaren ger samma form.** `user` är namnet eller `null`;
 * `null` är både en raderad användare och en händelse ett jobb orsakat
 * ([[ADR-0043 Tre loggar]] § Konsekvenser: personraderingen rör inte loggarna,
 * `user_id` blir en siffra som inte pekar på någon). Raden *a former user* är
 * rätt för båda, och de meningar som saknar en handlande — `container.purged`
 * och `account.deleted`, som jobbet skriver — bär inget `:user` alls och
 * möter därför aldrig ersättaren.
 *
 * **`changed` lyfts ur `meta` och resten lämnas kvar där.** Vyn behöver
 * fältnamnen för en ändring och ingenting annat ur `meta`; att skicka hela
 * arrayen hade gett klienten data ingen yta ritar, och `subject_id`,
 * kontonas ULID:er och nivåer hör till `/api`:s svar och inte hit.
 *
 * **Containernamnet kom med issue 126, efter samma princip som itemnamnet.**
 * Raderna i historikflikarna står INUTI sin container och behöver inte säga
 * vilken den är, men dashboardens händelsepanel visar rader över alla
 * användarens konton och måste göra det. Namnet slås därför upp på samma sätt
 * — när raden läses, för hela listan, i EN fråga — och en container som inte
 * längre finns får ett `null` i stället för ett namn, med vyns neutrala
 * ersättare *a deleted container*.
 *
 * **Containern är ett objekt och itemet ett namn, och skillnaden är med
 * flit.** En rad kan sakna container HELT (kontoraderna, led 3:
 * `account.deleted`) eller peka på en container som gallrats — och panelen får
 * inte säga *a deleted container* om en rad som handlar om ett konto. `null`
 * betyder därför "raden gäller ingen container" och `['name' => null]` "den
 * finns men går inte att namnge", precis som `item` är `null` när raden inte
 * handlar om ett item. Utan den skillnaden hade en kontorad burit en
 * containerrad den inte har.
 */
class PresentAuditEvents
{
    /**
     * Raderna i ritad ordning — samma ordning ListAuditEvents gav dem.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array{ulid: string, action: string, created_at: string, user: string|null, item: string|null, container: array{name: string|null}|null, changed: list<string>}>
     */
    public function handle(Collection $logs): array
    {
        $itemNames = $this->itemNames($logs);
        $containerNames = $this->containerNames($logs);

        return $logs
            ->map(fn (AuditLog $log): array => [
                'ulid' => $log->ulid,
                'action' => $log->action,
                'created_at' => $log->created_at->toIso8601String(),
                'user' => $log->user?->name,
                'item' => $log->item_id === null ? null : ($itemNames[$log->item_id] ?? null),
                'container' => $log->container_id === null
                    ? null
                    : ['name' => $containerNames[$log->container_id] ?? null],
                'changed' => $this->changed($log),
            ])
            ->values()
            ->all();
    }

    /**
     * Itemens namn per löpnummer, i EN fråga.
     *
     * En tom lista ger ingen fråga alls: en containerhistorik utan itemrader
     * — en ny container, eller en gäst som bara skapat den — ska inte kosta
     * ett anrop mot `item` för att svara `[]`.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, string>
     */
    private function itemNames(Collection $logs): array
    {
        $ids = $logs->pluck('item_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $namn = [];

        foreach (Item::query()->whereIn('id', $ids)->get(['id', 'name']) as $item) {
            $namn[$item->id] = $item->name;
        }

        return $namn;
    }

    /**
     * Containerns namn per löpnummer, i EN fråga — samma uppslag som
     * `itemNames()` och av samma skäl (issue 126).
     *
     * SoftDeletes' globala scope gäller: en container i papperskorgen är
     * raderad för läsaren, och en rad som handlar om den säger *a deleted
     * container*. Det är samma svar som ett gallrat item ger, och det är med
     * flit — de två går inte att skilja, och för läsaren är de samma sak.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, string>
     */
    private function containerNames(Collection $logs): array
    {
        $ids = $logs->pluck('container_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $namn = [];

        foreach (Container::query()->whereIn('id', $ids)->get(['id', 'name']) as $container) {
            $namn[$container->id] = $container->name;
        }

        return $namn;
    }

    /**
     * Fälten en ändring rörde, ur `meta.changed` — namnen och aldrig
     * innehållet ([[ADR-0043 Tre loggar]] § Händelseloggen).
     *
     * @return list<string>
     */
    private function changed(AuditLog $log): array
    {
        $changed = $log->meta['changed'] ?? [];

        return is_array($changed) ? array_values($changed) : [];
    }
}

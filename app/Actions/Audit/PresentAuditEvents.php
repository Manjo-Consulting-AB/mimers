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
 * **`agent` har tre lägen, och skillnaden bor i `agent_id`** (issue 428 ·
 * [[ADR-0087 MCP-server för agenter]] Riktning 3 och 8). `null` när en person
 * gjorde det själv; `{ulid, name}` när agenten finns; och
 * `{ulid: null, name: null}` när `agent_id` är satt men agenten tagits bort.
 * Det sista läget går inte att läsa ur relationen — den svarar `null` både för
 * "ingen agent" och "ingen rad" — så formen prövar `agent_id` först. Raden
 * blir då *via a removed agent* i stället för att påstå att personen handlade
 * själv. Relationen är eager-laddad av ListAuditEvents, så uppslaget kostar
 * ingen fråga per rad.
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
 * betyder därför "raden gäller ingen container" och `['ulid' => null, 'name'
 * => null]` "den finns men går inte att namnge", precis som `item` är `null`
 * när raden inte handlar om ett item. Utan den skillnaden hade en kontorad
 * burit en containerrad den inte har.
 *
 * **Containern bär sitt ULID så att raden kan länka till den** (M71 · issue
 * 1500). Namnet och ULID:et slås upp i SAMMA fråga och faller bort
 * tillsammans: en container som inte längre finns har varken ett namn att
 * rita eller en sida att gå till, och `HistoryRow.vue` ritar då sin neutrala
 * ersättare som text. ULID:et är containerns utåtriktade identifierare
 * (AGENTS.md § Databaskonventioner) — löpnumret stannar i databasen.
 */
class PresentAuditEvents
{
    /**
     * Raderna i ritad ordning — samma ordning ListAuditEvents gav dem.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array{ulid: string, action: string, created_at: string, user: string|null, agent: array{ulid: string|null, name: string|null}|null, item: string|null, container: array{ulid: string|null, name: string|null}|null, changed: list<string>}>
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
                'agent' => $log->agent_id === null
                    ? null
                    : ['ulid' => $log->agent?->ulid, 'name' => $log->agent?->name],
                'item' => $log->item_id === null ? null : ($itemNames[$log->item_id] ?? null),
                'container' => $log->container_id === null
                    ? null
                    : [
                        'ulid' => $containerNames[$log->container_id]['ulid'] ?? null,
                        'name' => $containerNames[$log->container_id]['name'] ?? null,
                    ],
                'changed' => $this->changed($log),
            ])
            ->values()
            ->all();
    }

    /**
     * Raderna vikta per dag i användarens tidszon — `days: [{date, rows}]`.
     *
     * Flyttad hit från ContainerHistoryController::days() i issue 271: den
     * globala historiken viker sin lista på exakt samma sätt, och två kopior
     * av vikningen hade glidit isär — dagrubriken är användarens dag
     * ([[ADR-0044 Användarens dag]]) och räknas på ett ställe.
     *
     * `created_at` är en tidsstämpel i UTC, och dagen är den användaren ser:
     * `setTimezone()` på en KOPIA, så radens egen tidsstämpel står orörd och
     * `handle()` ovan har redan skrivit den som ISO 8601. En händelse 23:30 UTC
     * hör till nästa dygn i Stockholm, och den hamnar därför i morgondagens
     * grupp — det är hela poängen med att låta servern räkna dagen
     * ([[ADR-0044 Användarens dag]] § Beslut 4).
     *
     * Raderna kommer i `created_at` fallande ordning, så dagarna hamnar i
     * samma ordning utan en egen sortering: en vikning bevarar insättnings-
     * ordningen, och `array_values()` gör listan till en lista.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return list<array{date: string, rows: list<array<string, mixed>>}>
     */
    public function byDay(Collection $logs, string $timezone): array
    {
        $rows = $this->handle($logs);

        $days = [];

        foreach ($logs->values() as $index => $log) {
            $date = $log->created_at->copy()->setTimezone($timezone)->toDateString();

            $days[$date] ??= ['date' => $date, 'rows' => []];
            $days[$date]['rows'][] = $rows[$index];
        }

        return array_values($days);
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
     * Containerns ULID och namn per löpnummer, i EN fråga — samma uppslag som
     * `itemNames()` och av samma skäl (issue 126, M71 · issue 1500).
     *
     * SoftDeletes' globala scope gäller: en container i papperskorgen är
     * raderad för läsaren, och en rad som handlar om den säger *a deleted
     * container*. Det är samma svar som ett gallrat item ger, och det är med
     * flit — de två går inte att skilja, och för läsaren är de samma sak.
     * ULID:et följer namnet: båda uteblir när containern inte längre finns,
     * eftersom raden inte ska länka till en sida som svarar 404.
     *
     * @param  Collection<int, AuditLog>  $logs
     * @return array<int, array{ulid: string, name: string}>
     */
    private function containerNames(Collection $logs): array
    {
        $ids = $logs->pluck('container_id')->filter()->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        $containern = [];

        foreach (Container::query()->whereIn('id', $ids)->get(['id', 'ulid', 'name']) as $container) {
            $containern[$container->id] = ['ulid' => $container->ulid, 'name' => $container->name];
        }

        return $containern;
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

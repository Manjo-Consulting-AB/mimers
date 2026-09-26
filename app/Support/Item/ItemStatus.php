<?php

namespace App\Support\Item;

use App\Actions\Item\ResolveItemDescendants;
use App\Models\Container;
use App\Models\Item;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Itemets status, härledd ur underträdet — se [[ADR-0040 Underträdets
 * summor]] § Beslut och issue 92.
 *
 * **OK betyder noll förfallna förekomster i underträdet.** Statusen räknas
 * över itemet och allt som hänger under det, transitivt nedåt längs
 * `item_link`-kanter där `relation` är `parent` — samma underträd som
 * kostnadsnedbrytningen i issue 91 och samma mängd som
 * App\Actions\Item\ResolveItemDescendants löser upp. Har något förfallit på
 * itemet eller under det är itemet inte OK, och användaren behöver inte öppna
 * sextio items för att hitta det som brinner.
 *
 * **Ingen kolumn och ingen cache.** `item` har ingen `status`-kolumn och får
 * ingen: den hade varit en andra sanning som måste hållas synkroniserad med
 * varje förändring i varje schema under itemet. Det som går att räkna fram
 * lagras inte.
 *
 * **Förfallen är datamodellens begrepp och inte en egen regel.**
 * [[Scheman och uppgifter]] § Förekomster definierar det som
 * `status = 'open' AND due_at < idag`, och det är exakt den formel
 * App\Http\Resources\ScheduleOccurrenceResource bär som `overdue`. Att
 * formulera om den här hade gett två svar på samma fråga. **`idag` är
 * ANVÄNDARENS kalenderdag** och inte serverns ([[ADR-0044 Användarens dag]]
 * § Beslut 1): servern går i UTC, och mellan midnatt och klockan två svensk
 * tid är det ännu i går där. Klassen får användaren som argument i
 * forItems() och hämtar den aldrig själv ur requesten eller `auth()` — den
 * anropas också utanför en request. Två saker följer av formeln, och båda är
 * med flit:
 *
 * - **Ett pausat schema räknas.** En pausad rad behåller sin öppna förekomst
 *   (issue 22a § Beslut 3), och den förekomsten är förfallen om datumet har
 *   passerat. Att hoppa över den hade gjort ett item med något förfallet
 *   under sig till OK.
 * - **Ett öppet beroende räknas.** En förekomst som väntar på en motpart går
 *   inte att stänga, men den är förfallen. Undantaget hör till todo-listan
 *   (ScheduleOccurrence::scopeTodoFor(), som svarar på *vad man kan göra nu*)
 *   och inte till statusen, som svarar på *står det illa till*.
 *
 * **Frågekostnaden är konstant: TVÅ frågor per lista**, oavsett hur många
 * rader listan bär och oavsett trädets djup och bredd — kanterna genom
 * ResolveItemDescendants och förekomsterna i overdueItems(). Slutningen sker
 * i minnet. Statusen räknas för varje rad i itemlistan, och en vandring per
 * rad vore precis den N+1 som hela åtkomstlösningen byggdes för att undvika.
 *
 * **Omfånget behöver inget eget filter, och det är inte självklart.**
 * Förekomsterna hämtas bara för de underträd som faktiskt efterfrågas, alltså
 * för de rader användaren ser. [[ADR-0028 Åtkomst på itemnivå]] regel 3 ger
 * en itemgrant hela underträdet, så den som ser ett item ser alltid allt
 * under det: en förfallen förekomst i ett underträd är alltid en förekomst
 * hon redan når. En förekomst på ett item utanför underträden — ett syskon
 * hon inte nått — påverkar därför ingen status hon ser.
 *
 * Klassen är MEDVETET inte en utvidgning av App\Actions\Access\ResolveItemScope:
 * den vandringen är behörighetens och rörs inte (issue 92 § In scope).
 */
class ItemStatus
{
    /**
     * Itemet och allt under det är fritt från förfallna förekomster.
     */
    public const OK = 'ok';

    /**
     * Minst en förekomst i underträdet är förfallen.
     */
    public const OVERDUE = 'overdue';

    public function __construct(
        private readonly ResolveItemDescendants $resolveItemDescendants,
    ) {}

    /**
     * Statusen för varje rad i en itemlista, nycklad på itemets ULID.
     *
     * Varje item i $items får en nyckel i svaret — vyns uppslag är detsamma
     * för alla rader och behöver ingen andra gren för ett item utan
     * förekomster, samma regel som `variants()` och `openOccurrences()` i
     * App\Http\Controllers\ItemController.
     *
     * Ett item som inte finns i något underträd får ingen nyckel alls; det
     * kan inte inträffa för en rad ur listan, eftersom varje efterfrågat item
     * är sin eget underträds första element.
     *
     * `$user` är den inloggade användaren och bär dagens kalenderdatum —
     * klassen slår aldrig upp den själv, se klassdocblocket.
     *
     * @param  iterable<Item>  $items  raderna i listan, levande och inom användarens omfång
     * @return array<string, string> itemets ULID → self::OK eller self::OVERDUE
     */
    public function forItems(Container $container, User $user, iterable $items): array
    {
        $ulidById = [];

        foreach ($items as $item) {
            $ulidById[$item->id] = $item->ulid;
        }

        if ($ulidById === []) {
            return [];
        }

        $subtrees = $this->resolveItemDescendants->forItems($container->id, array_keys($ulidById));

        $overdue = array_flip($this->overdueItems($this->subtreeIds($subtrees), $user->today()->toDateString()));

        $statuses = [];

        foreach ($subtrees as $itemId => $subtree) {
            $statuses[$ulidById[$itemId]] = $this->hasOverdue($subtree, $overdue)
                ? self::OVERDUE
                : self::OK;
        }

        return $statuses;
    }

    /**
     * Varje item som förekommer i något av underträden, en gång — underlaget
     * för förekomstfrågan. En lista över items användaren ser, alltså, och
     * aldrig containern i sin helhet.
     *
     * @param  array<int, list<int>>  $subtrees
     * @return list<int>
     */
    private function subtreeIds(array $subtrees): array
    {
        $ids = [];

        foreach ($subtrees as $subtree) {
            foreach ($subtree as $itemId) {
                $ids[$itemId] = true;
            }
        }

        return array_keys($ids);
    }

    /**
     * De item vars underträd bär minst en förfallen förekomst — EN fråga för
     * hela listan.
     *
     * `schedule_occurrence` har ingen `deleted_at` (issue 22 § Beslut 2):
     * raderingen av ett schema är mjuk och sitter på App\Models\Schedule, så
     * villkoret skrivs ut här. Ett mjukraderat ITEM behöver inget eget
     * villkor — det finns inte i något underträd, se
     * ResolveItemDescendants::loadChildrenByParent(), och dess förekomster är
     * därför aldrig med i $itemIds.
     *
     * `whereDate()` och inte en rå kolumnjämförelse: i sqlite lagras
     * DATE-kolumner med en tidskomponent, och ett förfallodatum ska inte bero
     * på klockslaget när frågan körs — samma skäl och samma form som
     * ScheduleOccurrence::scopeTodoFor().
     *
     * Jämförelsen sker mot användarens kalenderdatum som `$today` — en
     * `Y-m-d`-sträng ur `User::today()->toDateString()`, aldrig mot ett
     * `Carbon`-ögonblick ([[ADR-0044 Användarens dag]] § Beslut 5). Antalet
     * frågor är detsamma som före issue 136: dagens datum räknas i minnet.
     *
     * @param  list<int>  $itemIds
     * @param  string  $today  användarens dag, `Y-m-d`
     * @return list<int>
     */
    private function overdueItems(array $itemIds, string $today): array
    {
        if ($itemIds === []) {
            return [];
        }

        return DB::table('schedule_occurrence')
            ->join('schedule', 'schedule.id', '=', 'schedule_occurrence.schedule_id')
            ->whereIn('schedule.item_id', $itemIds)
            ->whereNull('schedule.deleted_at')
            ->where('schedule_occurrence.status', ScheduleOccurrence::STATUS_OPEN)
            ->whereDate('schedule_occurrence.due_at', '<', $today)
            ->distinct()
            ->pluck('schedule.item_id')
            ->all();
    }

    /**
     * Bär underträdet någon av de förfallna förekomsterna? Itemet självt är
     * med i listan — det första elementet — så en förekomst på itemet räknas
     * på samma väg som en på ett barnbarn.
     *
     * @param  list<int>  $subtree
     * @param  array<int, int>  $overdue  item_id → nyckel, ur array_flip()
     */
    private function hasOverdue(array $subtree, array $overdue): bool
    {
        foreach ($subtree as $itemId) {
            if (isset($overdue[$itemId])) {
                return true;
            }
        }

        return false;
    }
}

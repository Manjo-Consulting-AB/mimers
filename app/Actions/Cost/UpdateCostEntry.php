<?php

namespace App\Actions\Cost;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use App\Support\Cost\MinorUnits;
use Illuminate\Support\Facades\DB;

/**
 * Skriver en ändrad kostnadsrad och loggar händelsen — på ett ställe, så
 * webbens och `/api`:s uppdatering inte kan glida isär (issue 168,
 * [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * **`amount` och `currency` ändras alltid tillsammans**:
 * UpdateCostEntryRequest kräver paret med `required_with` åt båda hållen, så
 * det räcker att fråga efter `amount` — finns det finns också `currency` i
 * `validated()` och beloppet kan tolkas mot den nya valutan. Inga
 * tvärfältsregler mot radens befintliga tillstånd, så ingen
 * `validationData()`-sammanslagning som UpdateLoanRequest behövde.
 *
 * **`meta` bär fältens NAMN, och gamla och nya värdet för beloppet, valutan
 * och datumet** — aldrig för beskrivningen eller leverantören, som är
 * användarens fritext ([[ADR-0017 Missbruksvektorer]] § 7).
 *
 * En PATCH som inte ändrar något skriver ingen rad: skillnaden mot databasen
 * läses innan raden sparas.
 */
class UpdateCostEntry
{
    /**
     * Datumfältet. Gamla och nya värdet följer med i `meta` — som `Y-m-d`,
     * samma form kolumnen har (issue 109, [[ADR-0043 Tre loggar]]
     * § Händelseloggen).
     */
    private const DATE_FIELDS = ['incurred_on'];

    /**
     * Fälten vars värde får följa med i `meta`: datumet, valutan och
     * beloppet. `description` och `supplier` är användarens fritext och
     * följer aldrig med — bara deras NAMN står i `changed`.
     *
     * @var list<string>
     */
    private const VALUED_FIELDS = ['incurred_on', 'currency', 'amount'];

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  array<string, mixed>  $data  Den validerade kroppen.
     * @param  User  $actor  Den som ändrar raden; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Item $item, CostEntry $cost, array $data, User $actor): CostEntry
    {
        if (array_key_exists('amount', $data)) {
            $amount = MinorUnits::parse($data['amount'], $data['currency']);
            unset($data['amount']);
        }

        $cost->fill($data);

        if (isset($amount)) {
            $cost->amount = $amount;
        }

        $meta = $this->metaFor($cost);

        DB::transaction(function () use ($cost, $item, $actor, $meta): void {
            $cost->save();

            if ($meta === null) {
                return;
            }

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_COST_ENTRY_UPDATED,
                account: $item->container->account,
                user: $actor,
                container: $item->container,
                item: $item,
                subjectType: 'cost_entry',
                subjectUlid: $cost->ulid,
                meta: $meta,
            );
        });

        return $cost->load('createdByAccount');
    }

    /**
     * `meta` för de fält som ändrades, eller null när ingenting ändrades.
     *
     * Läses FÖRE `save()`: `getDirty()` är skillnaden mot databasen, och
     * efter en sparad rad är den tom.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: mixed, to: mixed}>}|null
     */
    private function metaFor(CostEntry $cost): ?array
    {
        $dirty = $cost->getDirty();

        if ($dirty === []) {
            return null;
        }

        $changed = array_keys($dirty);
        $values = [];

        foreach ($changed as $field) {
            if (! in_array($field, self::VALUED_FIELDS, true)) {
                continue;
            }

            $values[$field] = in_array($field, self::DATE_FIELDS, true)
                ? [
                    'from' => $cost->getOriginal($field)?->toDateString(),
                    'to' => $cost->{$field}->toDateString(),
                ]
                : ['from' => $cost->getOriginal($field), 'to' => $cost->{$field}];
        }

        $meta = ['changed' => $changed];

        if ($values !== []) {
            $meta['values'] = $values;
        }

        return $meta;
    }
}

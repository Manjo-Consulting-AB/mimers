<?php

namespace App\Actions\Loan;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Loan;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett ändrat lån — eller registrerar återlämningen — och loggar
 * händelsen, på ett ställe, så webbens och `/api`:s uppdatering inte kan glida
 * isär (issue 110, [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * **Bara de fält klienten faktiskt skickade skrivs tillbaka.** `$attributes`
 * är `array_intersect_key($request->validated(), $request->all())`, räknat i
 * kontrollern: `UpdateLoanRequest::validationData()` bär HELA det
 * sammanslagna tillståndet för att tvärfältsvalideringen ska se resultatet av
 * ändringen, och att fylla alltihop skulle skriva de inaktuella,
 * pre-lock-värdena över den nyss låsta raden (granskningsfynd på `/api`).
 *
 * Lånet läses om under item-låset, en current read (issue 22b § Beslut 9): en
 * PATCH byggd på en inaktuell rad ska inte omedvetet återöppna ett lån en
 * samtidig begäran just stängde.
 *
 * **En återlämning är en egen handling.** Går `returned_at` från null till ett
 * datum skrivs `loan.returned` i stället för `loan.updated` — det är den
 * händelse utlåningen finns för, och historiken (issue 116) formulerar den som
 * en egen mening. Båda skrivningarna är EN rad: en PATCH som både ändrar
 * fältet och lämnar tillbaka lånet loggas som återlämningen, med de ändrade
 * fälten i `meta.changed`.
 *
 * `meta.changed` är namnen på de fält som ändrades. Fritext — `borrower_name`,
 * `borrower_email` och `note` — följer aldrig med (issue 76 § Beslut 8,
 * [[ADR-0017 Missbruksvektorer]] § 7). `meta.values` bär gamla och nya värdet
 * för de tre datumfälten, som `Y-m-d`: en utlåningsdag har ingen tidszon
 * (issue 76 § Beslut 1).
 *
 * En ändring som inte ändrar något skriver ingen rad.
 */
class UpdateLoan
{
    /**
     * Datumfälten. Gamla och nya värdet följer med; fritexten står med flit
     * inte här.
     *
     * @var list<string>
     */
    private const VALUE_FIELDS = ['lent_at', 'due_at', 'returned_at'];

    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly AssertNoOpenLoan $assertNoOpenLoan,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  Fälten klienten skickade.
     * @param  User  $actor  Den som ändrar lånet; blir `user_id` på loggraden.
     *                       Behörigheten är redan prövad.
     */
    public function handle(Item $item, Loan $loan, array $attributes, User $actor): Loan
    {
        return DB::transaction(function () use ($item, $loan, $attributes, $actor): Loan {
            $lockedItem = $item->newQuery()
                ->whereKey($item->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedLoan = $lockedItem->loans()
                ->whereKey($loan->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedLoan->fill($attributes);

            if ($lockedLoan->returned_at === null) {
                $this->assertNoOpenLoan->handle($lockedItem, $lockedLoan);
            }

            // Skillnaden läses FÖRE `save()`: `getDirty()` är skillnaden mot
            // databasen, och efter en sparad rad är den tom.
            $meta = $this->metaFor($lockedLoan);

            $returned = $lockedLoan->getOriginal('returned_at') === null
                && $lockedLoan->returned_at !== null;

            $lockedLoan->save();

            if ($meta !== null) {
                $this->recordAuditEvent->handle(
                    action: $returned ? AuditLog::ACTION_LOAN_RETURNED : AuditLog::ACTION_LOAN_UPDATED,
                    account: $lockedItem->container->account,
                    user: $actor,
                    container: $lockedItem->container,
                    item: $lockedItem,
                    subjectType: 'loan',
                    subjectUlid: $lockedLoan->ulid,
                    meta: $meta,
                );
            }

            return $lockedLoan;
        });
    }

    /**
     * `meta` för de fält som ändrades, eller null när ingenting ändrades.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: string|null, to: string|null}>}|null
     */
    private function metaFor(Loan $loan): ?array
    {
        $dirty = $loan->getDirty();

        if ($dirty === []) {
            return null;
        }

        $changed = [];
        $values = [];

        foreach (array_keys($dirty) as $column) {
            $changed[] = $column;

            if (in_array($column, self::VALUE_FIELDS, true)) {
                $values[$column] = [
                    'from' => $this->date($loan->getOriginal($column)),
                    'to' => $this->date($loan->getAttribute($column)),
                ];
            }
        }

        $meta = ['changed' => $changed];

        if ($values !== []) {
            $meta['values'] = $values;
        }

        return $meta;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : null;
    }
}

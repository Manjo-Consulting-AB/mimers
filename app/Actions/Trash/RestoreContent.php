<?php

namespace App\Actions\Trash;

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Usage\AdjustUsage;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use App\Support\Plan\Entitlements;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Återställer en mjukraderad rad ur papperskorgen, med reglerna från issue
 * 20a § Beslut 7 och 8. En Action i stället för direkt i kontrollern, se
 * [[ADR-0024 Tunna controllers och actions]] — § Beslut 10: "RestoreContent
 * äger reglerna. Actionen tar typ och modell, prövar Beslut 8 och kallar
 * restore()." 20c återanvänder mönstret men inte klassen.
 *
 * Återställning är ETT steg, aldrig en kaskad (§ Beslut 7): `restore()` på
 * raden, ingenting annat. En bilaga som raderats separat ligger kvar i
 * papperskorgen när itemet återställs; en tagg kommer tillbaka på sina
 * items automatiskt eftersom pivotraderna ligger kvar och det är SoftDeletes
 * globala scope som gömt taggen.
 *
 * **Undantaget är kategorins underträd (issue 150).** En kategori raderas med
 * hela sitt underträd i EN transaktion med EN `deleted_at`
 * (App\Actions\Category\DeleteCategory), och återställningen tar tillbaka
 * exakt de rader som delar den tidsstämpeln — se `restoreSubtree()`. En
 * ättling som raderades vid ett annat tillfälle ligger kvar. Items behåller
 * sin `category_id` hela tiden och får alltså sin kategori tillbaka utan att
 * någon rör dem; ett item som under tiden pekats om, eller mist sin kategori,
 * behåller det den har.
 *
 * Det enda som kan blockera är en förälder som FORTFARANDE ligger i
 * papperskorgen (§ Beslut 8): en bilaga vars item är raderat, eller en
 * underkategori vars förälder är raderad. Utan regeln får man innehåll som
 * är återställt men osynligt — det ligger under något som fortfarande är
 * raderat. `data` pekar ut föräldern så klienten kan erbjuda att ta
 * tillbaka den i ett klick.
 *
 * Föräldern slås upp med en explicit `withTrashed()`-fråga — att gå genom
 * relationen `$attachment->item` respektive `$category->parent` vore att låta
 * den relaterade modellens SoftDeletes-scope svara null för en raderad
 * förälder, precis det fall som ska fångas här.
 *
 * `AdjustUsage` anropas här med `new`, inte konstruktorinjicering — medvetet,
 * se [[ADR-0024 Tunna controllers och actions]]. Räknaren är en beroendefri,
 * tillståndslös lövaction utan egna beroenden att injicera eller mocka, och
 * den här actionen är befintlig kod som 26a bara lägger ett anrop i; att trä
 * räknaren genom konstruktorn vore omarbetning utan mottagare.
 *
 * **Händelseloggen (issue 109) skrivs i samma transaktion, och bara för
 * item och bilaga.** En återställd kategori eller tagg hör till containerns
 * organisation och loggas av issue 111, inte här. En rad skrivs bara när
 * raden faktiskt LÅG i papperskorgen — en återställning av något som redan
 * är levande är ingen händelse.
 */
class RestoreContent
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  'item'|'attachment'|'category'|'tag'  $type
     * @param  User  $actor  Den som återställer; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     */
    public function handle(string $type, Item|Attachment|Category|Tag $model, User $actor): void
    {
        if ($model instanceof Attachment) {
            $item = Item::withTrashed()->find($model->item_id);

            if ($item !== null && $item->trashed()) {
                throw ApiException::make('trash.parent_deleted', [
                    'type' => 'item',
                    'ulid' => $item->ulid,
                ], 422);
            }
        }

        if ($model instanceof Category && $model->parent_id !== null) {
            $parent = Category::withTrashed()->find($model->parent_id);

            if ($parent !== null && $parent->trashed()) {
                throw ApiException::make('trash.parent_deleted', [
                    'type' => 'category',
                    'ulid' => $parent->ulid,
                ], 422);
            }
        }

        DB::transaction(function () use ($model, $actor): void {
            // Återställningen och en eventuell räknarökning i en transaktion
            // (issue 26a): en mjukraderad bilaga som blir levande igen kommer
            // tillbaka i kontots förbrukning, i samma transaktion som raden.
            //
            // Beslutet att öka grundas på radens tillstånd UNDER radlåset, inte
            // på instansen som kontrollern laddade före transaktionen: två
            // samtidiga återställningar av samma rad skulle annars båda se
            // `trashed()` och öka räknaren två gånger (granskningsfynd 1).
            // newQueryWithoutScopes — instansen är mjukraderad — och
            // lockForUpdate är en current read. Är raden redan borta (gallrad
            // mellan kontrollerns uppslag och den här transaktionen) finns
            // inget att återställa och ingen räknare att röra.
            $rad = $model->newQueryWithoutScopes()
                ->whereKey($model->getKey())
                ->lockForUpdate()
                ->first();

            if ($rad === null) {
                return;
            }

            $varMjukraderad = $rad->trashed();

            // Issue 28b Beslut 6 · Hålet som steg 5 öppnar: en bilaga som
            // återställs kommer tillbaka i kontots förbrukning, och en
            // återställning som skulle spränga kvoten nekas med samma kod och
            // data som uppladdningen (Entitlements::assertStorageWithinLimit,
            // issue 27b). Bara bilagor kostar byten — item, kategori och tagg
            // rör ingen räknare och ingen gräns, så de grenarna är orörda.
            // Kontrollen ligger före restore(): en nekad återställning lämnar
            // raden i papperskorgen.
            $byteSize = 0;

            if ($model instanceof Attachment && $varMjukraderad) {
                $byteSize = (int) $model->storedFile()->value('byte_size');
                $konto = Account::query()->whereKey($model->billed_account_id)->firstOrFail();

                (new Entitlements)->assertStorageWithinLimit($konto, $byteSize);
            }

            // restore() körs på instansen även när en samtidig återställning
            // redan hunnit först — den är då en no-op i databasen som bara
            // synkar instansens deleted_at för den som anropar.
            $model->restore();

            // Kategorin kom med ett underträd i papperskorgen (issue 150), och
            // återställningen tar med exakt de rader som kastades SAMTIDIGT:
            // samma `deleted_at` som raden hade. En ättling som raderades
            // tidigare har en annan tidsstämpel och ligger kvar — och släpps
            // inte in här, för då hade den blivit levande under en förälder
            // som just blivit det, vilket är precis det `trash.parent_deleted`
            // finns för att hindra i den andra riktningen.
            if ($model instanceof Category && $varMjukraderad && $rad->deleted_at !== null) {
                $this->restoreSubtree($model, $rad->deleted_at);
            }

            if ($model instanceof Attachment && $varMjukraderad) {
                (new AdjustUsage)->handle($model->billed_account_id, bytesDelta: $byteSize);
            }

            // Bara en rad som låg i papperskorgen är en händelse — en
            // återställning av något som redan är levande skriver ingenting
            // (issue 109). Kategori och tagg loggas av issue 111.
            if (! $varMjukraderad) {
                return;
            }

            if ($model instanceof Item) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_ITEM_RESTORED,
                    account: $model->container->account,
                    user: $actor,
                    container: $model->container,
                    item: $model,
                );

                return;
            }

            if ($model instanceof Attachment) {
                $item = Item::withTrashed()->findOrFail($model->item_id);

                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_ATTACHMENT_RESTORED,
                    account: $item->container->account,
                    user: $actor,
                    container: $item->container,
                    item: $item,
                    subjectType: 'attachment',
                    subjectUlid: $model->ulid,
                    meta: ['kind' => $model->kind],
                );
            }
        });
    }

    /**
     * Återställer de ättlingar till $category som kastades samtidigt — samma
     * `deleted_at` — i anroparens transaktion, så att trädet kommer tillbaka
     * som en enhet eller inte alls. Se klassens docblock och issue 150.
     *
     * Uppslaget är container-scopat och nycklat på tidsstämpeln, inte på en
     * vandring nedåt i flera frågor: hela den samtidigt kastade mängden är
     * exakt de rader återställningen kan komma att röra, och den är liten.
     * Vandringen nedåt från $category i minnet ser sedan till att bara DESS
     * ättlingar följer med — två oberoende träd som råkade kastas inom samma
     * sekund ska inte återställas ihop.
     *
     * Ingen egen `Gate::authorize()` och ingen egen loggrad: behörigheten är
     * prövad av anroparen på den översta raden, och händelseloggen har ingen
     * `category.restored` (issue 111 loggar raderingen, inte återställningen
     * av en kategori — se klassens docblock).
     */
    private function restoreSubtree(Category $category, Carbon $deletedAt): void
    {
        $rows = Category::query()->onlyTrashed()
            ->where('container_id', $category->container_id)
            ->where('deleted_at', $deletedAt)
            ->get();

        $childrenByParent = [];

        foreach ($rows as $row) {
            $childrenByParent[$row->parent_id ?? 0][] = $row;
        }

        $pending = $childrenByParent[$category->id] ?? [];

        while ($pending !== []) {
            $row = array_pop($pending);
            $row->restore();

            foreach ($childrenByParent[$row->id] ?? [] as $child) {
                $pending[] = $child;
            }
        }
    }
}

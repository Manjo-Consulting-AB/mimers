<?php

namespace App\Actions\Item;

use App\Actions\Notification\NotifyVersioningSkipped;
use App\Models\Item;
use App\Models\ItemFieldValue;
use App\Models\ItemVersion;
use App\Models\LegalHold;
use App\Models\User;
use App\Support\Usage\MetadataLedger;
use App\Support\Usage\MetadataSize;
use App\Support\Versioning\VersionLimit;
use Illuminate\Support\Facades\DB;

/**
 * Sparar itemets läge FÖRE en ändring som en version — [[ADR-0088
 * Versionshistorik för items]] Riktning 2, 3, 4 och 6 (issue 412).
 *
 * Anropas i SAMMA transaktion som skrivningen och FÖRE
 * `MetadataLedger::save($item)`, av App\Actions\Item\UpdateItem och
 * App\Actions\Item\UpdateItemNotes. Det är därför ögonblicksbilden läses ur
 * `getRawOriginal()` — databasens värden — och inte ur modellens fyllda
 * attribut: itemet är redan `fill()`-at med de nya värdena av anroparen.
 *
 * **Ingenting sparas när ingenting ändras.** `isDirty()` falskt och samma
 * taggmängd är ingen händelse: en PATCH med samma värden ska inte kosta en
 * version. Taggar byts av App\Actions\Item\SyncItemTags, men itemet har
 * ingen tidigare version som kunde bära det gamla bytet, så `UpdateItem`
 * skickar den önskade mängden hit — jämförelsen äger rum här, och fälten och
 * taggarna sparas som EN version tillsammans.
 *
 * **Skurven (Riktning 4).** Skriver samma aktör igen inom
 * `konton.versioning.burst_minutes` sparas ingen ny version; den nyaste
 * versionens `replaced_at` flyttas framåt i stället, så fönstret glider.
 * Aktören är användaren och, när en agent skriver, även agenten
 * (`replaced_by_agent_id`, fylls i av issue 415). En återställning (issue
 * 413) bryter alltid skurven med `breakBurst: true`.
 *
 * **Taket (Riktning 6).** Efter en ny version tas itemets äldsta versioner
 * bort tills antalet är högst `VersionLimit::for()`. Borttagningen går genom
 * `MetadataLedger::release()`, så räknaren och raderna följs åt.
 *
 * **Ägaren betalar (Riktning 3).** Versionens `billed_account_id` är
 * containerns ägare, även när en inbjuden skriver. Ryms versionen inte i
 * ägarens kvot tas äldsta versioner bort tills den ryms; ryms den ändå inte
 * sparas ingen version, skrivningen går igenom ändå, och ägaren får
 * `versioning.skipped` (en gång per container och dygn).
 *
 * **Rättslig spärr (Riktning 6).** Under spärr på ägarkontot tas inga
 * versioner bort. Ryms versionen inte sparas den ändå, utan kvotprövning, så
 * att ingenting raderas — och inget i svaret eller vyn avslöjar spärren.
 */
class RecordItemVersion
{
    public function __construct(
        private readonly NotifyVersioningSkipped $notifyVersioningSkipped,
    ) {}

    /**
     * @param  User  $actor  Den som skriver ändringen.
     * @param  int|null  $agentId  Agenten, när en agent skriver (issue 415);
     *                             en del av aktören i skurven.
     * @param  bool  $breakBurst  Sant när återställningen (issue 413) sparar
     *                            nuläget: den ska alltid bli en egen version.
     * @param  list<int>|null  $tagIds  Den önskade taggmängden när anroparen
     *                                  byter taggar, annars null (mängden
     *                                  rörs inte). Jämförs med den nuvarande
     *                                  för att avgöra om något ändras.
     */
    public function handle(
        Item $item,
        User $actor,
        ?int $agentId = null,
        bool $breakBurst = false,
        ?array $tagIds = null,
    ): void {
        $container = $item->container;
        $limit = VersionLimit::for($container);

        if ($limit === 0) {
            return;
        }

        $currentTagIds = $this->currentTagIds($item);
        $desiredTagIds = $tagIds === null ? $currentTagIds : $this->normalizeTagIds($tagIds);

        if (! $item->isDirty() && $desiredTagIds === $currentTagIds) {
            return;
        }

        $latest = $item->versions()->first();

        if (! $breakBurst && $latest !== null && $this->continuesBurst($latest, $actor, $agentId)) {
            // Samma arbetspass: läget före den FÖRSTA skrivningen står kvar,
            // och fönstret skjuts framåt (Riktning 4).
            $latest->replaced_at = now();
            $latest->save();

            return;
        }

        $version = $this->snapshot($item, $currentTagIds, $actor, $agentId);
        $owner = $container->account;
        $bytes = MetadataSize::of($version);
        $hold = LegalHold::covers($owner);

        // Ägarens kvot (Riktning 3). Under spärr prövas den inte alls
        // (Riktning 6), och ingenting tas då bort.
        if (! $hold) {
            $this->makeRoom($item, $owner->id, $bytes);
        }

        if ($hold || MetadataLedger::fits($owner->id, $bytes)) {
            // Prövningen är redan gjord ovan, och den låste räknarraden i
            // samma transaktion. `checkQuota: false` hindrar att samma
            // fråga ställs en andra gång och att en kapplöpning fäller
            // skrivningen som skulle gå igenom.
            MetadataLedger::save($version, checkQuota: false);

            if (! $hold) {
                $this->trim($item, $limit);
            }

            return;
        }

        // Rymdes den inte: ingen version, men skrivningen fortsätter, och
        // ägaren får veta det en gång per container och dygn.
        $this->notifyVersioningSkipped->handle($container);
    }

    /**
     * Tar bort itemets äldsta versioner tills den nya ryms i ägarens kvot.
     */
    private function makeRoom(Item $item, int $payerId, int $bytes): void
    {
        while (! MetadataLedger::fits($payerId, $bytes)) {
            $oldest = $this->oldest($item);

            if ($oldest === null) {
                return;
            }

            MetadataLedger::release($oldest);
            $oldest->delete();
        }
    }

    /**
     * Håller taket: tar bort de äldsta tills antalet är högst `$limit`.
     */
    private function trim(Item $item, int $limit): void
    {
        while (ItemVersion::query()->where('item_id', $item->id)->count() > $limit) {
            $oldest = $this->oldest($item);

            if ($oldest === null) {
                return;
            }

            MetadataLedger::release($oldest);
            $oldest->delete();
        }
    }

    private function oldest(Item $item): ?ItemVersion
    {
        return ItemVersion::query()
            ->where('item_id', $item->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * Fortsätter den nyaste versionen samma arbetspass?
     */
    private function continuesBurst(ItemVersion $latest, User $actor, ?int $agentId): bool
    {
        if ((int) $latest->replaced_by_user_id !== $actor->id) {
            return false;
        }

        if (($latest->replaced_by_agent_id === null ? null : (int) $latest->replaced_by_agent_id) !== $agentId) {
            return false;
        }

        return $latest->replaced_at->greaterThanOrEqualTo(
            now()->subMinutes((int) config('konton.versioning.burst_minutes', 10)),
        );
    }

    /**
     * Ögonblicksbilden — fälten som de står i databasen FÖRE ändringen, med
     * taggarna sorterade (Riktning 2).
     */
    private function snapshot(Item $item, array $tagIds, User $actor, ?int $agentId): ItemVersion
    {
        $version = new ItemVersion;

        $version->item_id = $item->id;
        // Ägaren betalar, inte skribenten (Riktning 3).
        $version->billed_account_id = $item->container->account_id;
        $version->name = (string) $item->getRawOriginal('name');
        $version->description = $item->getRawOriginal('description');
        $version->notes = $item->getRawOriginal('notes');
        $version->field_values = $this->fieldValues($item);
        $version->category_id = $item->getRawOriginal('category_id');
        $version->cover_attachment_id = $item->getRawOriginal('cover_attachment_id');
        $version->icon = $item->getRawOriginal('icon');
        $version->icon_color = $item->getRawOriginal('icon_color');
        $version->tag_ids = $tagIds;
        $version->replaced_by_user_id = $actor->id;
        $version->replaced_by_agent_id = $agentId;
        $version->replaced_at = now();

        return $version;
    }

    /**
     * Itemets fältvärden nycklade på fältets id ([[ADR-0089 Egna fält på
     * items]] § Riktning 9). Värdet är den kolumn som är satt för fältets
     * typ, som rå sträng: en återställning (issue 413) skickar tillbaka den
     * genom samma väg som ett värde ur kroppen.
     *
     * @return array<int, string>
     */
    private function fieldValues(Item $item): array
    {
        $values = [];

        ItemFieldValue::query()
            ->where('item_id', $item->id)
            ->orderBy('item_field_id')
            ->get(['item_field_id', 'value_text', 'value_number', 'value_date'])
            ->each(function (ItemFieldValue $row) use (&$values): void {
                $value = $row->getRawOriginal('value_text')
                    ?? $row->getRawOriginal('value_number')
                    ?? $row->getRawOriginal('value_date');

                if ($value !== null) {
                    $values[(string) $row->item_field_id] = (string) $value;
                }
            });

        return $values;
    }

    /**
     * Taggmängden som den står i pivottabellen — samma läsning som
     * App\Actions\Item\SyncItemTags gör, förbi SoftDeletes-scopet.
     *
     * @return list<int>
     */
    private function currentTagIds(Item $item): array
    {
        return $this->normalizeTagIds(
            DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all(),
        );
    }

    /**
     * @param  array<int, int|string>  $tagIds
     * @return list<int>
     */
    private function normalizeTagIds(array $tagIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $tagIds)));
        sort($ids);

        return $ids;
    }
}

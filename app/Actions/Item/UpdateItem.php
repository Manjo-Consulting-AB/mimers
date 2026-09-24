<?php

namespace App\Actions\Item;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett ändrat item, byter taggmängden och loggar vad som ändrades —
 * på ett ställe, så webbens och `/api`:s uppdatering inte kan glida isär
 * (issue 109, [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]; kontrollerna
 * behåller requestens form (`/api` skiljer på ett utelämnat `tags` och ett
 * tomt, webben skickar alltid hela mängden) och actionen äger skrivningen.
 *
 * **`$item` kommer färdigifylld** — kontrollern har gjort `fill()` och satt
 * `category_id` och `cover_attachment_id`. Actionen läser skillnaden mot
 * databasen INNAN den sparar, för det är skillnaden som är händelsen.
 *
 * **En ändring loggas med fältens namn, inte med deras innehåll.**
 * `meta.changed` är namnen på de fält som ändrades. Fritext — `name`,
 * `description`, `notes`, `manufacturer`, `model`, `serial_number`,
 * `position_note` — följer aldrig med, inte ens som gammalt värde: loggen
 * får inte bli ett andra register över vad användaren skrivit. `meta.values`
 * bär gamla och nya värdet för de fält som inte ÄR fritext: datumen
 * `purchased_at` och `warranty_until`, och referenserna `category` och
 * `cover`, som ULID:er — aldrig löpnummer (AGENTS.md § Databaskonventioner).
 *
 * **En ändring som inte ändrar något skriver ingen rad.** En PATCH med
 * samma värden som förut sparar ingenting (Eloquent rör ingen rad när inget
 * är smutsigt) och loggar ingenting.
 *
 * Ändringen, taggbytet och raden ligger i EN transaktion, samma skäl som i
 * App\Actions\Item\CreateItem.
 */
class UpdateItem
{
    /**
     * Kolumnnamn som får ett läsarvänligt namn i `meta`. `category_id` och
     * `cover_attachment_id` är pekare och kallas det användaren kallar dem.
     *
     * @var array<string, string>
     */
    private const FIELD_NAMES = [
        'category_id' => 'category',
        'cover_attachment_id' => 'cover',
    ];

    /**
     * Datumfälten. Gamla och nya värdet följer med, som `Y-m-d` — samma form
     * kolumnen har, en dag har ingen tidszon (issue 13a § Beslut 5).
     *
     * @var list<string>
     */
    private const DATE_FIELDS = ['purchased_at', 'warranty_until'];

    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly SyncItemTags $syncItemTags,
    ) {}

    /**
     * @param  User  $actor  Den som ändrar itemet; blir `user_id` på
     *                       loggraderna. Behörigheten är redan prövad.
     * @param  bool  $syncTags  Sant när anroparen vill ersätta taggmängden.
     *                          `/api` sätter det ur `has('tags')`, webben
     *                          alltid.
     * @param  Collection<int, Tag>  $tags  Den önskade mängden, när den ska
     *                                      synkas.
     */
    public function handle(Item $item, User $actor, bool $syncTags, Collection $tags): void
    {
        $meta = $this->metaFor($item);

        DB::transaction(function () use ($item, $actor, $syncTags, $tags, $meta): void {
            $item->save();

            if ($meta !== null) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_ITEM_UPDATED,
                    account: $item->container->account,
                    user: $actor,
                    container: $item->container,
                    item: $item,
                    meta: $meta,
                );
            }

            if ($syncTags) {
                $this->syncItemTags->handle($item, $tags, $actor);
            }
        });
    }

    /**
     * `meta` för de fält som ändrades, eller null när ingenting ändrades.
     *
     * Läses FÖRE `save()`: `getDirty()` är skillnaden mot databasen, och
     * efter en sparad rad är den tom. `getOriginal()` ger det gamla värdet
     * med sin cast, så ett datum kommer tillbaka som ett datum.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: string|null, to: string|null}>}|null
     */
    private function metaFor(Item $item): ?array
    {
        $dirty = $item->getDirty();

        if ($dirty === []) {
            return null;
        }

        $changed = [];
        $values = [];

        foreach (array_keys($dirty) as $column) {
            $field = self::FIELD_NAMES[$column] ?? $column;
            $changed[] = $field;

            $value = $this->valueFor($item, $column);

            if ($value !== null) {
                $values[$field] = $value;
            }
        }

        $meta = ['changed' => $changed];

        if ($values !== []) {
            $meta['values'] = $values;
        }

        return $meta;
    }

    /**
     * Gamla och nya värdet för ett fält som får bära dem, annars null.
     *
     * @return array{from: string|null, to: string|null}|null
     */
    private function valueFor(Item $item, string $column): ?array
    {
        if (in_array($column, self::DATE_FIELDS, true)) {
            return [
                'from' => $item->getOriginal($column)?->toDateString(),
                'to' => $item->{$column}?->toDateString(),
            ];
        }

        if ($column === 'category_id') {
            return [
                'from' => $this->categoryUlid($item->getOriginal($column)),
                'to' => $this->categoryUlid($item->category_id),
            ];
        }

        if ($column === 'cover_attachment_id') {
            return [
                'from' => $this->attachmentUlid($item->getOriginal($column)),
                'to' => $this->attachmentUlid($item->cover_attachment_id),
            ];
        }

        return null;
    }

    /**
     * ULID:en för kategorin med det löpnumret, eller null. Läsningen går förbi
     * Eloquents SoftDeletes-scope och returnerar kolumnens råa värde: raden
     * bär identifieraren och ingenting annat, så det finns inget att skydda —
     * en kategori som mjukraderats sedan itemet fick den ger fortfarande sitt
     * ULID. Att den då inte går att slå upp vid visning är issue 116:s sak.
     */
    private function categoryUlid(?int $id): ?string
    {
        return $id === null ? null : DB::table('category')->where('id', $id)->value('ulid');
    }

    private function attachmentUlid(?int $id): ?string
    {
        return $id === null ? null : DB::table('attachment')->where('id', $id)->value('ulid');
    }
}

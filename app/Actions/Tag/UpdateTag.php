<?php

namespace App\Actions\Tag;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett ändrat namn eller en ändrad färg och loggar vad som ändrades —
 * på ett ställe, så webbens och `/api`:s uppdatering inte kan glida isär (issue
 * 111, [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]:
 * `App\Http\Controllers\TagController::update()` och
 * `App\Http\Controllers\Api\TagController::update()` bar fram till issue 111
 * var sin `fill()` + `save()`. Den ena kontrollerns docblock kallade den
 * "ingen Action" — skrivningen bar ingen regel — och det stämmer fram till
 * dess att raden ska skrivas i handlingens transaktion.
 *
 * **`UpdateTagRequest` delas av båda ytorna** och är oförändrad:
 * `->ignore($this->route('tag'))` gör att taggen kan spara sitt eget namn
 * oförändrat, och ett `color: null` betyder "ingen färg" — det är inte en
 * tömning att skydda sig mot.
 *
 * **En ändring loggas med fältens namn, inte med deras innehåll.**
 * `meta.changed` är namnen på de fält som ändrades. `name` är fritext och följer
 * aldrig med. `color` är en värdelista och bär gamla och nya värdet i
 * `meta.values`.
 *
 * **En ändring som inte ändrar något skriver ingen rad.**
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen.
 */
class UpdateTag
{
    /**
     * Fälten som får bära gamla och nya värdet i `meta.values`. `name` står
     * med flit inte här.
     *
     * @var list<string>
     */
    private const VALUE_FIELDS = ['color'];

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som ändrar taggen; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     * @param  array<string, mixed>  $attributes  `name` och/eller `color`,
     *                                            redan validerade av
     *                                            UpdateTagRequest.
     */
    public function handle(Container $container, Tag $tag, User $actor, array $attributes): Tag
    {
        $tag->fill($attributes);

        // Läsningen sker FÖRE `save()`: `getDirty()` är skillnaden mot
        // databasen, och efter en sparad rad är den tom.
        $meta = $this->metaFor($tag);

        DB::transaction(function () use ($container, $tag, $actor, $meta): void {
            $tag->save();

            if ($meta !== null) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_TAG_UPDATED,
                    account: $container->account,
                    user: $actor,
                    container: $container,
                    subjectType: 'tag',
                    subjectUlid: $tag->ulid,
                    meta: $meta,
                );
            }
        });

        return $tag;
    }

    /**
     * `meta` för de fält som ändrades, eller null när ingenting ändrades.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: string|int|bool|null, to: string|int|bool|null}>}|null
     */
    private function metaFor(Tag $tag): ?array
    {
        $dirty = $tag->getDirty();

        if ($dirty === []) {
            return null;
        }

        $changed = [];
        $values = [];

        foreach (array_keys($dirty) as $column) {
            $changed[] = $column;

            if (in_array($column, self::VALUE_FIELDS, true)) {
                $values[$column] = [
                    'from' => $tag->getOriginal($column),
                    'to' => $tag->getAttribute($column),
                ];
            }
        }

        $meta = ['changed' => $changed];

        if ($values !== []) {
            $meta['values'] = $values;
        }

        return $meta;
    }
}

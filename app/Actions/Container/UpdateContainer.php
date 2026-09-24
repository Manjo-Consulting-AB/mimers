<?php

namespace App\Actions\Container;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skriver ett ändrat containerhuvud och loggar vad som ändrades — på ett
 * ställe, så webbens och `/api`:s uppdatering inte kan glida isär (issue 111,
 * [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]:
 * `App\Http\Controllers\ContainerController::update()` och
 * `App\Http\Controllers\Api\ContainerController::update()` bar fram till issue
 * 111 var sin `fill()` + `save()`. Sedan raden ska skrivas i handlingens
 * transaktion är skrivningen värd en egen Action — och en andra avskrift av
 * loggraden i den ena kontrollern hade varit två sanningar om vad en ändring
 * av en container ÄR.
 *
 * **`UpdateContainerRequest` delas redan av båda ytorna** och är oförändrad:
 * `name`, `kind`, `description` och `currency`, alla `sometimes`. Actionen tar
 * emot `validated()` och fyller raden själv, så `sometimes`-skillnaden mellan
 * "töm fältet" och "rör det inte" ligger kvar i requesten.
 *
 * **Behörigheten prövas av anroparen**, som förut — `Gate::authorize('update',
 * $container)`. `manageAccess`-grinden och den här skrivningen hör inte ihop,
 * och en Action som prövade den själv skulle pröva den två gånger (issue 54
 * § Beslut 3).
 *
 * **En ändring loggas med fältens namn, inte med deras innehåll.**
 * `meta.changed` är namnen på de fält som ändrades. `name` och `description`
 * är fritext och följer aldrig med. `kind` och `currency` är värdelistor och
 * bär gamla och nya värdet i `meta.values`.
 *
 * **En ändring som inte ändrar något skriver ingen rad** — samma regel som
 * `App\Actions\Schedule\UpdateSchedule` (issue 110): en PATCH med samma
 * värden som förut sparar ingenting och loggar ingenting.
 */
class UpdateContainer
{
    /**
     * Fälten som får bära gamla och nya värdet i `meta.values`. `name` och
     * `description` står med flit inte här.
     *
     * @var list<string>
     */
    private const VALUE_FIELDS = ['kind', 'currency'];

    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som ändrar containern; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     * @param  array<string, mixed>  $attributes  Kroppens validerade fält.
     */
    public function handle(Container $container, User $actor, array $attributes): Container
    {
        $container->fill($attributes);

        // Läsningen sker FÖRE `save()`: `getDirty()` är skillnaden mot
        // databasen, och efter en sparad rad är den tom.
        $meta = $this->metaFor($container);

        DB::transaction(function () use ($container, $actor, $meta): void {
            $container->save();

            if ($meta !== null) {
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_CONTAINER_UPDATED,
                    account: $container->account,
                    user: $actor,
                    container: $container,
                    subjectType: 'container',
                    subjectUlid: $container->ulid,
                    meta: $meta,
                );
            }
        });

        return $container;
    }

    /**
     * `meta` för de fält som ändrades, eller null när ingenting ändrades.
     *
     * @return array{changed: list<string>, values?: array<string, array{from: string|int|bool|null, to: string|int|bool|null}>}|null
     */
    private function metaFor(Container $container): ?array
    {
        $dirty = $container->getDirty();

        if ($dirty === []) {
            return null;
        }

        $changed = [];
        $values = [];

        foreach (array_keys($dirty) as $column) {
            $changed[] = $column;

            if (in_array($column, self::VALUE_FIELDS, true)) {
                $values[$column] = [
                    'from' => $container->getOriginal($column),
                    'to' => $container->getAttribute($column),
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

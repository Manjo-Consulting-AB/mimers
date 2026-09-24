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
 * Ersätter itemets taggmängd och loggar bytet — paret på ett ställe, så
 * webben och `/api` inte kan glida isär (issue 109, [[ADR-0043 Tre loggar]]
 * § Händelseloggen).
 *
 * `sync()`s ersättningssemantik (en utelämnad tagg kopplas bort, en ny
 * kopplas in) men ett KONSTANT antal frågor oavsett antal taggar (issue 13b
 * § Beslut 6): `BelongsToMany::sync()` kopplar en pivotrad per fråga. Den
 * nuvarande mängden läses direkt ur pivottabellen och inte genom
 * `tags()`-relationen — relationen bär SoftDeletes globala scope och hade
 * gömt pivotraderna för mjukraderade taggar som `sync()` fortfarande ser
 * (§ Beslut 3).
 *
 * **Ingen rad när ingenting ändrades.** En PATCH som skickar samma
 * taggmängd som förut är ingen händelse — `meta` skulle bara säga att
 * ingenting hände, och regeln är att en ändring som inte ändrar något inte
 * skriver någon rad.
 *
 * **ULID:er, aldrig namn.** Raden säger vilka taggar som kom och gick, inte
 * vad de heter; namnet är användarens fritext och slås upp när raden visas
 * (issue 116). Uppslaget läser kolumnens råa värde förbi SoftDeletes-scopet:
 * en bortkopplad tagg kan vara mjukraderad sedan länge, och raden bär ändå
 * bara identifieraren.
 *
 * Actionen öppnar ingen egen transaktion: den anropas inifrån
 * App\Actions\Item\CreateItem och App\Actions\Item\UpdateItem, som båda äger
 * en — samma skäl som App\Actions\Audit\RecordAuditEvent.
 */
class SyncItemTags
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  Collection<int, Tag>  $tags  Den önskade mängden.
     */
    public function handle(Item $item, Collection $tags, User $actor): void
    {
        $desired = $tags->pluck('id')->all();
        $current = DB::table('item_tag')->where('item_id', $item->id)->pluck('tag_id')->all();

        $toAttach = array_values(array_diff($desired, $current));
        $toDetach = array_values(array_diff($current, $desired));

        if ($toAttach !== []) {
            $item->tags()->attach($toAttach);
        }

        if ($toDetach !== []) {
            $item->tags()->detach($toDetach);
        }

        if ($toAttach === [] && $toDetach === []) {
            return;
        }

        $this->recordAuditEvent->handle(
            action: AuditLog::ACTION_ITEM_TAGS_CHANGED,
            account: $item->container->account,
            user: $actor,
            container: $item->container,
            item: $item,
            meta: [
                'added' => $tags->whereIn('id', $toAttach)->pluck('ulid')->values()->all(),
                'removed' => DB::table('tag')->whereIn('id', $toDetach)->pluck('ulid')->values()->all(),
            ],
        );
    }
}

<?php

namespace App\Actions\Tag;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en tagg och skriver `tag.deleted` — på ett ställe, så webbens och
 * `/api`:s radering inte kan glida isär (issue 111, [[ADR-0043 Tre loggar]]
 * § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]:
 * `App\Http\Controllers\TagController::destroy()` och
 * `App\Http\Controllers\Api\TagController::destroy()` bar fram till issue 111
 * var sin `delete()`.
 *
 * **Raderas nekas aldrig.** Taggen är platt och har inget barn att skydda
 * (issue 12 § Beslut 7, till skillnad från kategorin i issue 11 § Beslut 7), så
 * ingen domänfelkod kan komma ur den här vägen och ingen `ApiErrorTranslator`
 * behövs hos anroparen.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen.
 */
class DeleteTag
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraden.
     */
    public function handle(Container $container, Tag $tag, User $actor): void
    {
        DB::transaction(function () use ($container, $tag, $actor): void {
            $tag->delete();

            // Namnet är fritext och följer aldrig med i `meta` (issue 111).
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_TAG_DELETED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'tag',
                subjectUlid: $tag->ulid,
            );
        });
    }
}

<?php

namespace App\Actions\Tag;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Skapar en tagg i containern — se issue 56a § Beslut 7 och issue 12
 * § Beslut 4 och 8.
 *
 * Kroppen är `App\Http\Controllers\Api\TagController::store()`s, övertagen
 * oförändrad, och **återupplivningen är hela avvikelsen från ren CRUD**:
 * finns en MJUKRADERAD tagg med samma namn i containern (`withTrashed()` — en
 * vanlig fråga ser den inte) återställs DEN raden i stället för att en ny
 * skapas. Samma ULID som före raderingen, `deleted_at` nollställs, `color`
 * sätts till det nya värdet. Utan den kan en användare inte skapa om en tagg
 * hon nyss raderade — `StoreTagRequest` avvisar bara en AKTIV dubblett
 * (`Rule::unique` med `whereNull('deleted_at')`), så en ny rad hade krockat
 * med det unika indexet `(container_id, name)`.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen — samma
 * linje som issue 54 § Beslut 3, 55a § Beslut 8 och 55b § Beslut 7.
 *
 * Sedan issue 111 skrivs `tag.created` i SAMMA transaktion som raden
 * ([[ADR-0043 Tre loggar]] § Händelseloggen). Återupplivningen loggas som en
 * skapelse: för användaren är det samma handling, och namnet är fritext och
 * följer aldrig med i `meta`.
 */
class CreateTag
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som skapar taggen; blir `user_id` på
     *                       loggraden. Behörigheten är redan prövad.
     */
    public function handle(Container $container, string $name, ?string $color, User $actor): Tag
    {
        return DB::transaction(function () use ($container, $name, $color, $actor): Tag {
            $tag = $container->tags()->withTrashed()->where('name', $name)->first();

            if ($tag !== null) {
                $tag->restore();
                $tag->color = $color;
                $tag->save();
            } else {
                $tag = new Tag(['name' => $name, 'color' => $color]);
                $tag->container_id = $container->id;
                $tag->save();
            }

            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_TAG_CREATED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'tag',
                subjectUlid: $tag->ulid,
            );

            return $tag;
        });
    }
}

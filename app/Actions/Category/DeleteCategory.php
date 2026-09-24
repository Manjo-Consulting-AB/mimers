<?php

namespace App\Actions\Category;

use App\Actions\Audit\RecordAuditEvent;
use App\Exceptions\Api\ApiException;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en kategori och skriver `category.deleted` — på ett ställe, så
 * webbens och `/api`:s radering inte kan glida isär (issue 111,
 * [[ADR-0043 Tre loggar]] § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]:
 * `App\Http\Controllers\CategoryController::destroy()` och
 * `App\Http\Controllers\Api\CategoryController::destroy()` bar fram till issue
 * 111 var sin avskrift av de två villkoren. Den ena kontrollerns docblock
 * pekade själv ut vägen hit — "den dag de glider isär är det den gemensamma
 * Actionen som ska till, inte en tredje avskrift" — och raden som ska skrivas
 * i handlingens transaktion är den dagen.
 *
 * **Ingen kaskad och ingen "radera ändå"-knapp** (issue 11 § Beslut 7, issue
 * 13a § Beslut 9): en kategori med barn nekas med antalet barn, en med items
 * med antalet items. En radering som tyst tömmer klassificeringen på tjugo
 * items är precis den tysta dataförlusten [[ADR-0008 Soft delete och
 * papperskorg]] finns till för att undvika.
 *
 * Undantaget bubblar upp till anroparen, som formulerar sitt eget svar: `/api`
 * låter det bli `{"error":{"code":…}}`, webben fångar det och översätter med
 * App\Support\Frontend\ApiErrorTranslator till en mening i en ruta över
 * trädet — samma kod, samma rad, två svar.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen, samma linje
 * som CreateCategory och MoveCategory.
 */
class DeleteCategory
{
    public function __construct(private readonly RecordAuditEvent $recordAuditEvent) {}

    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraden.
     *
     * @throws ApiException 422 `category.has_children` eller
     *                      `category.has_items`.
     */
    public function handle(Container $container, Category $category, User $actor): void
    {
        $childrenCount = $category->children()->count();

        if ($childrenCount > 0) {
            throw ApiException::make('category.has_children', ['children' => $childrenCount], 422);
        }

        $itemsCount = $category->items()->count();

        if ($itemsCount > 0) {
            throw ApiException::make('category.has_items', ['items' => $itemsCount], 422);
        }

        DB::transaction(function () use ($container, $category, $actor): void {
            $category->delete();

            // Namnet är fritext och följer aldrig med i `meta` (issue 111,
            // [[ADR-0043 Tre loggar]] § Händelseloggen).
            $this->recordAuditEvent->handle(
                action: AuditLog::ACTION_CATEGORY_DELETED,
                account: $container->account,
                user: $actor,
                container: $container,
                subjectType: 'category',
                subjectUlid: $category->ulid,
            );
        });
    }
}

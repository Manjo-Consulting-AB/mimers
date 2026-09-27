<?php

namespace App\Actions\Category;

use App\Actions\Audit\RecordAuditEvent;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Mjukraderar en kategori med hela sitt underträd och skriver
 * `category.deleted` en gång per raderad rad — på ett ställe, så webbens och
 * `/api`:s radering inte kan glida isär (issue 111, [[ADR-0043 Tre loggar]]
 * § Händelseloggen).
 *
 * Bryts ut enligt [[ADR-0024 Tunna controllers och actions]]:
 * `App\Http\Controllers\CategoryController::destroy()` och
 * `App\Http\Controllers\Api\CategoryController::destroy()` bar fram till issue
 * 111 var sin avskrift av de två villkoren. Den ena kontrollerns docblock
 * pekade själv ut vägen hit — "den dag de glider isär är det den gemensamma
 * Actionen som ska till, inte en tredje avskrift" — och raden som ska skrivas
 * i handlingens transaktion är den dagen.
 *
 * **Kaskaden kom med issue 150.** Fram till dess nekade actionen en kategori
 * med barn (`category.has_children`) och en med items (`category.has_items`),
 * för att en radering inte skulle tömma klassificeringen tyst (issue 11
 * § Beslut 7, issue 13a § Beslut 9). Med papperskorgen är raderingen inte
 * tyst längre och går att ångra ([[ADR-0008 Soft delete och papperskorg]]
 * § Uppföljning 2026-09-26), så båda villkoren utgick och raderingen tar hela
 * underträdet i stället.
 *
 * **Ett `deleted_at` för hela underträdet.** Raderna raderas i EN transaktion
 * med samma tidsstämpel, och det är den som binder dem samman: papperskorgen
 * visar bara den översta (en ättling vars förälder raderades samtidigt listas
 * inte för sig), och en återställning tar tillbaka exakt de rader som delar
 * tidsstämpeln (App\Actions\Trash\RestoreContent). Sätts tiden per rad hade
 * de två reglerna tappat varandra.
 *
 * **En ättling som redan ligger i papperskorgen rörs inte** — dess
 * `deleted_at` står kvar, den behåller sin plats i papperskorgen och
 * återställs inte med den översta. Underträdet är därför de LEVANDE ättlingar
 * som hänger samman med kategorin; vandringen stannar vid en mjukraderad rad i
 * stället för att gå igenom den.
 *
 * **Items behåller sin `category_id`.** Ingen nollning, ingen kaskad över
 * `item`: kategorin ligger i papperskorgen och kan komma tillbaka med
 * klassificeringen i behåll. Först gallringen nollställer pekaren
 * (App\Actions\Trash\PurgeContent::category()).
 *
 * **Händelseloggen får en rad per raderad kategori**, inte en för hela
 * kaskaden: det är kategorin som raderas, och varje rad bär sin egen ULID.
 *
 * Undantaget bubblar upp till anroparen, som formulerar sitt eget svar:
 * `/api` låter det bli `{"error":{"code":…}}`, webben fångar det och översätter
 * med App\Support\Frontend\ApiErrorTranslator till en mening i en ruta över
 * trädet — samma kod, samma rad, två svar. Efter issue 150 finns ingen kod
 * kvar att kasta för raderingen; kvar gör `ApiErrorTranslator` för de koder
 * som andra ytor kastar.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen, samma linje
 * som CreateCategory och MoveCategory.
 */
class DeleteCategory
{
    public function __construct(
        private readonly RecordAuditEvent $recordAuditEvent,
        private readonly ResolveCategoryDescendants $resolveCategoryDescendants,
    ) {}

    /**
     * @param  User  $actor  Den som raderar; blir `user_id` på loggraderna.
     */
    public function handle(Container $container, Category $category, User $actor): void
    {
        DB::transaction(function () use ($container, $category, $actor): void {
            // EN tidsstämpel för hela underträdet, satt explicit och inte av
            // SoftDeletes: det är den som gör trädet till en post i
            // papperskorgen och en återställning till en enda operation.
            $deletedAt = now();

            // Underträdet kommer ur App\Actions\Category\ResolveCategoryDescendants
            // och inte ur en egen vandring: "kategorin och allt under den" är
            // redan formulerad på ett ställe, och en andra avskrift glider isär
            // (samma skäl som issue 15a och 46 delar den). Den läser de LEVANDE
            // ättlingarna — en rad som redan ligger i papperskorgen är inte med,
            // och behåller därför sitt eget `deleted_at`.
            $ids = $this->resolveCategoryDescendants->handle($category);

            foreach (Category::query()->whereIn('id', $ids)->orderBy('id')->get() as $rad) {
                $rad->deleted_at = $deletedAt;
                $rad->save();

                // Namnet är fritext och följer aldrig med i `meta` (issue 111,
                // [[ADR-0043 Tre loggar]] § Händelseloggen).
                $this->recordAuditEvent->handle(
                    action: AuditLog::ACTION_CATEGORY_DELETED,
                    account: $container->account,
                    user: $actor,
                    container: $container,
                    subjectType: 'category',
                    subjectUlid: $rad->ulid,
                );
            }
        });
    }
}

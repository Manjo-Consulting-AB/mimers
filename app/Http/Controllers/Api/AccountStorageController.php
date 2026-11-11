<?php

namespace App\Http\Controllers\Api;

use App\Actions\Security\RecordSecurityEvent;
use App\Actions\Trash\PurgeContent;
use App\Exceptions\Api\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RemoveStorageRequest;
use App\Http\Resources\StorageEntryResource;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\LegalHold;
use App\Models\SecurityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Kontots storage-yta i nedgraderingen — issue 28 steg 2, se
 * [[Planer och kvoter]] § Nedgradering och [[ADR-0009 Kvoter och livscykel]].
 * En kontoruta, inte en containerruta (Beslut 2): listan är kontots LEVANDE
 * bilagor oavsett vilken container de ligger i — kontot är det som belastas,
 * och bilagor kan ligga i containers kontot inte äger. Rutterna ligger på
 * toppnivå under `{account}` och prövas mot den nya App\Policies\AccountPolicy,
 * INTE mot ContainerPolicy (Beslut 4).
 *
 * GET listar bilagorna sorterade på storlek fallande — störst först, för det
 * är den ordning som gör valet snabbt. DELETE rensar ett urval: raderar
 * bilagorna för gott och minskar kontots förbrukning i en atomär transaktion (Beslut
 * 6). Ingen paginering (Beslut 2), av samma skäl som issue 15a § Beslut 8.
 *
 * INGEN behörighetslogik bor här: varje metod börjar med Gate::authorize()
 * mot AccountPolicy — viewStorage för listningen, manageStorage för
 * rensningen. Medlemskap i kontot (`account_user`) avgör, ingen
 * rollskillnad, och INGEN read_only-kontroll: ett fruset konto får rensa
 * sina bilagor, det är hela poängen med ytan (Beslut 4). Kontot i rutten är
 * inte den inloggade användarens enda konto, så det hämtas aldrig ur
 * användaren — grinden avgör.
 */
class AccountStorageController extends Controller
{
    /**
     * GET /api/accounts/{account}/storage — 200.
     *
     * Kontots levande bilagor (`attachment.billed_account_id = {account}`
     * och `deleted_at IS NULL`), sorterade på `stored_file.byte_size`
     * fallande med `attachment.id` fallande som andrasortering — två bilagor
     * på samma fil får ändå en stabil ordning. Mjukraderade bilagor kommer
     * aldrig med: SoftDeletes globala scope sköter det.
     *
     * `stored_file` eagras för byte_size, och `item`+`container` eagras med
     * `withTrashed()` — en bilaga vars item eller container ligger i
     * papperskorgen räknas fortfarande mot kontot (item-mjukraderingen rör
     * inte bilagan, se issue 26a) och ska synas i urvalslistan. Sorteringen
     * går genom en JOIN mot stored_file — `byte_size` bor där, inte på
     * attachment (Att se upp med), och `select('attachment.*')` hindrar
     * stored_file-kolumnerna från att skugga attachment-radens värden.
     */
    public function index(Account $account): JsonResponse
    {
        Gate::authorize('viewStorage', $account);

        $attachments = Attachment::query()
            ->select('attachment.*')
            ->where('billed_account_id', $account->id)
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->with([
                'storedFile',
                'item' => fn ($query) => $query->withTrashed()->with([
                    'container' => fn ($query) => $query->withTrashed(),
                ]),
                // En containerbilaga (issue 158) har inget item: dess container
                // kommer ur den här relationen i stället, och den måste vara
                // eagrad av samma skäl som itemets — annars gör listan en
                // fråga per containerbild. Samma with() som
                // Settings\StorageController::index(), vars fråga den här är
                // ordagrant. withTrashed() av samma skäl som ovan: en bilaga i
                // en container i papperskorgen räknas fortfarande mot kontot.
                'container' => fn ($query) => $query->withTrashed(),
            ])
            ->orderByDesc('stored_file.byte_size')
            ->orderByDesc('attachment.id')
            ->get();

        return StorageEntryResource::collection($attachments)->response();
    }

    /**
     * DELETE /api/accounts/{account}/storage — 200 med antalet borttagna och
     * kontots förbrukning EFTER rensningen.
     *
     * RemoveStorageRequest har redan bevisat att varje ULID finns i
     * `attachment`, tillhör kontot och är levande — en ULID som inte gör det
     * är 422 `validation.failed` för HELA begäran, ingenting raderas (Beslut
     * 6). Grinden är AccountPolicy::manageStorage: medlemskap, ingen
     * read_only-kontroll — ett fruset konto får rensa (Beslut 4).
     *
     * Själva rensningen går genom App\Actions\Trash\PurgeContent::attachment()
     * för varje bilaga, i en yttre transaktion så att ett fel mitt i batchen
     * rullar tillbaka ALLT — bulkraderingen är atomär. Bilagorna hamnar INTE i
     * papperskorgen (issue 1060): när papperskorgen räknas i kvoten frigör en
     * mjukradering ingenting, och nedgraderingens poäng är att användaren
     * kommer under gränsen genom att välja. Raderna tas bort för gott, räknaren
     * på filen går ned, bytena lämnar kontots förbrukning omedelbart och
     * fysiskt gallras de av 20b. Ett omslag nollställs av främmande nyckelns
     * ON DELETE SET NULL. Vanlig radering av en bilaga på ett item går
     * fortfarande till papperskorgen.
     *
     * Täcks kontot av en rättslig spärr (LegalHold::covers) är det 422
     * `storage.legal_hold` före transaktionen och ingenting raderas.
     *
     * Svaret bär antalet faktiskt borttagna bilagor och kontots
     * `usage_counter.storage_bytes` efter transaktionen — klienten kan visa
     * hur långt hon har kvar utan ett andra anrop. Antalet räknas INTE ur
     * `$attachments->count()`: en bilaga som någon annan hunnit radera
     * samtidigt skulle då räknas med. PurgeContent::attachment() returnerar
     * inget, så raden kontrolleras före och efter anropet.
     */
    public function destroy(
        RemoveStorageRequest $request,
        Account $account,
        PurgeContent $purgeContent,
        RecordSecurityEvent $recordSecurityEvent,
    ): JsonResponse {
        Gate::authorize('manageStorage', $account);

        // Issue 1060: en rättslig spärr stoppar raderingen innan något rörs.
        if (LegalHold::covers($account)) {
            throw ApiException::make('storage.legal_hold', [], 422);
        }

        $attachments = Attachment::query()
            ->where('billed_account_id', $account->id)
            ->whereIn('ulid', $request->validated('attachments'))
            ->get()
            ->keyBy('ulid');

        $removed = 0;
        DB::transaction(function () use ($attachments, $purgeContent, &$removed): void {
            foreach ($attachments as $attachment) {
                // PurgeContent::attachment() returnerar inget: raden kontrolleras
                // före och efter, så att en bilaga någon annan hunnit radera
                // inte räknas.
                $fanns = Attachment::withTrashed()->whereKey($attachment->getKey())->exists();

                $purgeContent->attachment($attachment);

                if ($fanns && ! Attachment::withTrashed()->whereKey($attachment->getKey())->exists()) {
                    $removed++;
                }
            }
        });

        // Issue 113: tömd lagring — samma rad som webbens väg skriver.
        $recordSecurityEvent->handle(
            action: SecurityLog::ACTION_STORAGE_EMPTIED,
            account: $account,
            user: $request->user(),
            ip: $request->ip(),
            userAgent: $request->userAgent(),
            meta: ['removed' => $removed],
        );

        $storageBytes = (int) (DB::table('usage_counter')
            ->where('account_id', $account->id)
            ->value('storage_bytes') ?? 0);

        return response()->json([
            'data' => [
                'removed' => $removed,
                'storage_bytes' => $storageBytes,
            ],
        ]);
    }
}

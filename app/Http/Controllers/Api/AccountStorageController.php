<?php

namespace App\Http\Controllers\Api;

use App\Actions\Attachment\TrashAttachment;
use App\Actions\Security\RecordSecurityEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RemoveStorageRequest;
use App\Http\Resources\StorageEntryResource;
use App\Models\Account;
use App\Models\Attachment;
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
 * är den ordning som gör valet snabbt. DELETE rensar ett urval: mjukraderar
 * bilagorna och minskar kontots förbrukning i en atomär transaktion (Beslut
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
     * Själva rensningen går genom App\Actions\Attachment\TrashAttachment för
     * varje bilaga, i en yttre transaktion så att ett fel mitt i batchen
     * rullar tillbaka ALLT — bulkraderingen är atomär. Bilagorna hamnar i
     * papperskorgen som vanligt och kan återställas (Beslut 5); bytena
     * lämnar kontots räknare omedelbart och gallras 30 dagar senare av 20b.
     *
     * Svaret bär antalet faktiskt borttagna bilagor och kontots
     * `usage_counter.storage_bytes` efter transaktionen — klienten kan visa
     * hur långt hon har kvar utan ett andra anrop. Antalet räknas INTE ur
     * `$attachments->count()`: den SELECT:en körs före radlåset i
     * TrashAttachment::handle, och en bilaga som någon annan mjukraderar
     * samtidigt skulle då räknas med trots att handle() tyst hoppar över den
     * (granskningsfynd — samma teknik som PurgeAttachment::handle).
     * `$removed` summeras ur handle()s returvärde i stället: bara rader som
     * faktiskt mjukraderades här räknas.
     */
    public function destroy(
        RemoveStorageRequest $request,
        Account $account,
        TrashAttachment $trashAttachment,
        RecordSecurityEvent $recordSecurityEvent,
    ): JsonResponse {
        Gate::authorize('manageStorage', $account);

        $attachments = Attachment::query()
            ->where('billed_account_id', $account->id)
            ->whereIn('ulid', $request->validated('attachments'))
            ->get()
            ->keyBy('ulid');

        $removed = 0;
        DB::transaction(function () use ($request, $attachments, $trashAttachment, &$removed): void {
            foreach ($attachments as $attachment) {
                // Användaren skickas in: att tömma lagringen är ett klick av
                // en människa, inte ett jobb. Bara nedgraderingen och
                // gallringen skriver rader utan aktör (issue 109).
                if ($trashAttachment->handle($attachment, $request->user())) {
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

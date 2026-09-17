<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Attachment\TrashAttachment;
use App\Actions\Plan\ReadPlanUsage;
use App\Http\Controllers\Controller;
use App\Http\Requests\Account\RemoveStorageRequest;
use App\Http\Resources\StorageEntryResource;
use App\Models\Account;
use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Number;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Lagringsytan — nedgraderingens steg 2, se issue 66b, [[Planer och kvoter]]
 * § Nedgradering och [[ADR-0009 Kvoter och livscykel]].
 *
 * **Ett konto i taget, och kontot kommer ur ett FÄLT på listningen.** Rutten
 * `/settings/storage` har ingen `{account}`-parameter i sökvägen: sidan väljer
 * konto i en väljare och byter med `?account=`, precis som
 * App\Http\Controllers\Settings\PlanController (66a § Beslut 1) och
 * App\Http\Controllers\WebhookEndpointController (65b § Beslut 1). En sida som
 * byter konto ska inte behöva byta URL.
 *
 * **Rensningen har kontot i RUTTEN.** `DELETE /settings/storage/{account}` och
 * inte `/settings/storage` med kontot i kroppen. Skälet är att
 * App\Http\Requests\Account\RemoveStorageRequest — som issuen delar rakt av
 * med `/api` och som ligger utanför omfångsrutan — läser kontot ur
 * `$this->route('account')` för att pröva varje ULID mot rätt kontos bilagor.
 * Utan kontot i rutten hade den delningen varit omöjlig: en ny FormRequest
 * eller en ändring i den delade vore den enda vägen runt, och båda är utanför
 * omfångsrutan. Att objektet kommer ur rutten är dessutom regeln och inte
 * undantaget i den här appen (issue 53c § Beslut 1, samma linje som
 * `/settings/accounts/{account}`).
 *
 * **Grinden prövas mot DET valda kontot, aldrig mot "användarens första"**
 * (66a § Beslut 2). Ett konto hon inte är medlem i ger 403 — inte en tom sida
 * med någon annans bilagor. `viewStorage` för listningen, `manageStorage` för
 * rensningen, och **ingen read_only-kontroll i någondera**: ett fruset konto
 * får rensa, det är hela poängen med ytan (28 § Beslut 4). Vyn döljer därför
 * ingen knapp för ett fryst konto.
 *
 * **Ingen behörighetslogik och ingen sifferlogik bor här** ([[ADR-0021
 * Frontendteknik]], [[ADR-0024 Tunna controllers och actions]]): räkningen av
 * bilagorna är App\Actions\Attachment\TrashAttachment (samma action som
 * `/api`:s bilagerutt och samma som 28 § Beslut 5 beskriver), och förbrukningen
 * läses ur `usage_counter` — aldrig som en egen SUM, av samma skäl som
 * ReadPlanUsage anger.
 *
 * **Listningen är kontots, inte containerns** (28 § Beslut 2):
 * `attachment.billed_account_id` avgör, och bilagor kan ligga i containers
 * kontot inte äger och belastar det ändå. Frågan är `/api`:s fråga ordagrant —
 * samma join, samma sortering, samma `withTrashed()` — och den står här i
 * stället för i en delad action därför att `app/Actions/**` ligger utanför
 * den här issuen omfång. Glider de två isär är det ett fel någon ser: sviten i
 * tests/Feature/Kvot/NedgraderingTest.php prövar `/api`-sidan.
 */
class StorageController extends Controller
{
    /**
     * Sessionsnyckeln rensningens sammanfattning flashas under. Stavas bara
     * här — `destroy()` lägger den och `index()` läser den, samma mönster som
     * WebhookEndpointController::SECRET_SESSION_KEY (65b § Beslut 3).
     *
     * Nyckeln behövs för att `flash.status` bär en KOD och inga parametrar
     * (issue 51 § Beslut 5): meningen om antalet borttagna och förbrukningen
     * efteråt har två tal i sig, och de talen är serverns (Beslut 8).
     */
    private const REMOVED_SESSION_KEY = 'storage_removed';

    /**
     * GET /settings/storage — det valda kontots bilagor, störst först.
     *
     * `accounts` är ALLA konton användaren är med i, som på plansidan: att
     * utelämna ett konto ur listan vore att dölja en knapp, och
     * behörighetskontroller görs i policies (M10 § ingressen).
     *
     * `attachments` byggs ur App\Http\Resources\StorageEntryResource — samma
     * resurs som `/api` svarar med — med ETT fält lagt bredvid, `inTrash`:
     * det hör inte i `/api` (en bilaga vars item eller container ligger i
     * papperskorgen räknas fortfarande mot kontot och ska gå att rensa bort),
     * men vyn måste kunna MARKERA raden, annars ser summan ut att vara fel.
     * Fältet läggs i kontrollern, samma mönster som `disabledBySystem` i
     * App\Http\Controllers\WebhookEndpointController::index() (65b § Beslut 8).
     *
     * `usage` är kontots lagringsutrymme mot taket, ur
     * App\Actions\Plan\ReadPlanUsage — SAMMA handling och samma tal som
     * plansidan visar, så de två sidorna inte kan säga olika saker. Hela
     * handlingen körs och bara `usage.storage` läses: att räkna om bytena här
     * vore en andra sanning vid sidan av räknaren, och att bygga en egen
     * läsning vore en ny action (`app/Actions/**` ligger utanför omfånget).
     *
     * `removed` är rensningens sammanfattning ur flashen, och är `null` vid
     * varje annan visning än den direkt efter en rensning (Beslut 8).
     */
    public function index(Request $request, ReadPlanUsage $readPlanUsage): Response
    {
        $user = $request->user();

        $accounts = $user->accounts()->orderBy('name')->get();

        abort_if($accounts->isEmpty(), 404);

        $account = $this->selectedAccount($request, $accounts);

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

        return Inertia::render('Settings/Storage', [
            'accounts' => $accounts->map(fn (Account $konto): array => [
                'ulid' => $konto->ulid,
                'name' => $konto->name,
            ])->all(),

            'account' => [
                'ulid' => $account->ulid,
                'name' => $account->name,
            ],

            'attachments' => $attachments->map(fn (Attachment $bilaga): array => [
                ...StorageEntryResource::make($bilaga)->resolve($request),

                'inTrash' => $bilaga->item->trashed() || $bilaga->item->container->trashed(),
            ])->all(),

            'usage' => $readPlanUsage->handle($account)['usage']['storage'],

            'removed' => $request->session()->get(self::REMOVED_SESSION_KEY),
        ]);
    }

    /**
     * DELETE /settings/storage/{account} — rensar ett urval och skickar
     * sammanfattningen vidare i flashen.
     *
     * RemoveStorageRequest har redan bevisat att varje ULID finns i
     * `attachment`, tillhör kontot i rutten och är levande — en ULID som inte
     * gör det är 422 för HELA begäran, ingenting raderas (28 § Beslut 6).
     * Grinden är AccountPolicy::manageStorage: medlemskap, ingen
     * read_only-kontroll (Beslut 4 och 7).
     *
     * Rensningen är `/api`:s rensning: App\Actions\Attachment\TrashAttachment
     * per bilaga i en yttre transaktion, så ett fel mitt i batchen rullar
     * tillbaka allt. Bilagorna hamnar i papperskorgen och kan återställas
     * (Beslut 5 och 6) — ingen fysisk radering och ingen tömning av
     * papperskorgen, och ingen automatisk radering: steg 4 är ett jobb, inte en
     * knapp.
     *
     * `$removed` summeras ur handle()s returvärde och räknas aldrig ur
     * antalet valda ULID:er: en bilaga som någon annan mjukraderar samtidigt
     * hoppas tyst över av handle(), och svaret ska bära vad som FAKTISKT
     * hände. Förbrukningen läses ur `usage_counter` EFTER transaktionen och
     * formateras här — talet är serverns hela vägen ut (Beslut 8).
     */
    public function destroy(
        RemoveStorageRequest $request,
        Account $account,
        TrashAttachment $trashAttachment,
    ): RedirectResponse {
        Gate::authorize('manageStorage', $account);

        $attachments = Attachment::query()
            ->where('billed_account_id', $account->id)
            ->whereIn('ulid', $request->validated('attachments'))
            ->get()
            ->keyBy('ulid');

        $removed = 0;
        DB::transaction(function () use ($attachments, $trashAttachment, &$removed): void {
            foreach ($attachments as $attachment) {
                if ($trashAttachment->handle($attachment)) {
                    $removed++;
                }
            }
        });

        $storageBytes = (int) (DB::table('usage_counter')
            ->where('account_id', $account->id)
            ->value('storage_bytes') ?? 0);

        return redirect()
            ->route('settings.storage', ['account' => $account->ulid])
            ->with(self::REMOVED_SESSION_KEY, [
                'removed' => $removed,
                'storageLabel' => Number::fileSize($storageBytes),
            ]);
    }

    /**
     * Sidans konto: `?account=` om det är ett konto användaren är med i,
     * annars hennes första.
     *
     * Samma tre grenar som App\Http\Controllers\Settings\PlanController::
     * selectedAccount() (66a § Beslut 2), och samma skäl: kontot är sidans
     * hela innehåll, så en ULID som pekar på någon annans konto får inte tyst
     * bli hennes eget — den går vidare till `Gate::authorize()` i index() och
     * blir 403, aldrig en tom sida. Ett ULID som inte finns alls är 404, samma
     * svar som varje annan rutt med en `{account}`-parameter ger. Urvalet är
     * inte en grind — grinden är `Gate::authorize()`.
     *
     * @param  Collection<int, Account>  $accounts
     */
    private function selectedAccount(Request $request, Collection $accounts): Account
    {
        $ulid = (string) $request->query('account', '');

        $valt = $accounts->firstWhere('ulid', $ulid);

        if ($valt !== null) {
            return $valt;
        }

        if ($ulid === '') {
            return $accounts->first();
        }

        return Account::query()->where('ulid', $ulid)->firstOrFail();
    }
}

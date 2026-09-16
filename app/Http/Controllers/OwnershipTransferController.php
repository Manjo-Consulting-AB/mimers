<?php

namespace App\Http\Controllers;

use App\Actions\Notification\CreateNotification;
use App\Actions\OwnershipTransfer\AcceptOwnershipTransfer;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\OwnershipTransfer\AcceptOwnershipTransferRequest;
use App\Http\Requests\OwnershipTransfer\StoreOwnershipTransferRequest;
use App\Http\Resources\ContainerResource;
use App\Http\Resources\OwnershipTransferResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification as NotificationModel;
use App\Models\OwnershipTransfer;
use App\Models\User;
use App\Notifications\OwnershipTransferNotification;
use App\Support\Frontend\ActiveContainer;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Plan\Entitlements;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Webbens ägarbytesyta — avsändarens sida i pärmen och mottagarens inkorg, se
 * issue 67b § Beslut 1–9. API-motsvarigheten är
 * App\Http\Controllers\Api\OwnershipTransferController; den transaktion som
 * flyttar containern, förbrukningen, åtkomsterna och planen rörs inte här —
 * den anropar App\Actions\OwnershipTransfer\AcceptOwnershipTransfer oförändrad
 * (Beslut 5 i omfångsrutan).
 *
 * **Två sidor på två nivåer** (Beslut 1). Avsändarens sida ligger UNDER pärmen
 * (`/containers/{container}/transfer`), för hon står i den. Mottagarens ligger
 * på TOPPNIVÅ (`/transfers`), för hon har den inte ännu — den är inte hennes
 * att navigera i, och en sida under pärmen hade krävt att hon först fick
 * pärmen.
 *
 * **Ingen token, ingen `{token}`-rutt och ingen session** (Beslut 5). Det här
 * är den medvetna skillnaden mot inbjudningarna
 * (App\Http\Controllers\InvitationResponseController § Beslut 2): en inbjudan
 * ger läsrätt till en pärm, ett ägarbyte överlåter hela pärmen, och en
 * bärartoken i ett mejl till en overifierad adress vore en kapabilitet att ta
 * emot någon annans pärm. App\Notifications\OwnershipTransferNotification
 * pekar därför på den statiska sökvägen `/transfers`, och mottagaren hittar
 * sin begäran på IDENTITET — sitt konto, eller sin verifierade adress.
 *
 * **Ingen ny FormRequest** (Beslut 5 i omfångsrutan). `StoreOwnershipTransferRequest`
 * och `AcceptOwnershipTransferRequest` delas rakt av med `/api`, inklusive
 * `prohibits` mellan de två mottagarvägarna och `Rule::in(['read', 'write'])`
 * på `retain_access_level`. Den senare har olika regler beroende på om raden
 * bär `to_account_id` eller `to_email`, och den logiken är dess.
 *
 * **Ingen behörighetslogik bor här.** Sidan och skrivningarna anropar
 * `Gate::authorize()` och litar på App\Policies\ContainerPolicy, precis som
 * API-kontrollern gör. Mottagarsidans tre metoder anropar INGEN policy: den
 * som radens mottagarväg pekar ut ÄR behörig, och en rad som inte pekar på
 * användaren ska vara osynlig — 404, aldrig ett "du har inte behörighet" som
 * avslöjar att raden finns (Beslut 8).
 *
 * **`recipientQuery()` och `inboxQuery()` är medvetet en andra kopia** av
 * App\Http\Controllers\Api\OwnershipTransferController::recipientQuery() och
 * ::inboxQuery(). `app/Http/Controllers/Api/**` ligger utanför den här
 * issuens omfång, och två formuleringar av "vilka rader är den här
 * användarens" är exakt vad API-kontrollern varnar för — de får inte glida
 * isär. Ändras den ena ska den andra ändras; utbrytningen till en delad Action
 * ligger utanför rutan. Samma avvägning som
 * App\Http\Controllers\LoanController::assertNoOpenLoan() gjorde i 67a.
 *
 * **Samma sak gäller `createTransfer()`** nedan: initieringen — dubblettspärren
 * och mottagarvägen — bor i `Api\OwnershipTransferController::store()`, och
 * den här ytan behöver samma skrivning. Se § Frågor och antaganden i PR:en.
 */
class OwnershipTransferController extends Controller
{
    /**
     * De nivåer en avsändare kan behålla, se Beslut 2.
     *
     * `StoreOwnershipTransferRequest` tillåter `read` och `write` och inget
     * annat: laddern har fyra steg sedan [[ADR-0028 Åtkomst på itemnivå]], men
     * requesten avvisar de två avancerade, och vyn erbjuder därför två. Den
     * uppfinner ingen nivå requesten nekar. Listan skickas som prop och skrivs
     * aldrig av i JavaScript — samma teknik som `levels` på delningssidan.
     *
     * @var list<string>
     */
    private const RETAIN_LEVELS = ['read', 'write'];

    /**
     * GET /containers/{container}/transfer — avsändarens sida.
     *
     * Grinden är `viewTransfers()` — medlemskap i ägarkontot och ingenting
     * mer, för att SE listan är att läsa. Skrivningen grindas av `transfer()`
     * och skickas som flaggan `can.transfer`, precis som `can.manage` på
     * delningssidan: flaggan ritar formuläret, grinden är policyn, och POST
     * auktoriserar med `Gate::authorize()` oavsett vad sidan visade.
     *
     * **Plangrinden ritar sidan, den gömmer den inte** (Beslut 3).
     * `planNotice` är svaret på samma fråga som POST ställer, formulerad ur
     * samma nyckel som fältfelet — samma `featureNotice()` och samma
     * tvåstegsöversättning som App\Http\Controllers\WebhookEndpointController
     * (65b § Beslut 5) — så meningen på sidan och felet vid POST aldrig kan
     * säga olika saker. Att dölja ytan vore att dölja funktionen man ska
     * kunna köpa.
     *
     * `items` är pärmens levande items och är undantagsvalet i formuläret —
     * det är hela skillnaden mellan "skicka allt" och "behåll inköpspriset".
     * `retainLevels` är de två nivåerna ovan.
     */
    public function show(
        Request $request,
        Container $container,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
    ): Response {
        Gate::authorize('viewTransfers', $container);

        // Ladda ägarkontot uttryckligen: planfrågan läser det, och policyn
        // läser samma relation — samma resonemang som ContainerController.
        $container->loadMissing('account');

        $transfers = $container->transfers()
            ->with(['container', 'toAccount'])
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Containers/Transfers', [
            'container' => ContainerResource::make($container)->resolve($request),
            'transfers' => $transfers->map(fn (OwnershipTransfer $transfer): array => [
                ...OwnershipTransferResource::make($transfer)->resolve($request),

                // Presentation BREDVID resursen, aldrig inuti den
                // ([[ADR-0021 Frontendteknik]]): en ULID är oläsbar för den
                // publik den här listan har, och `/api` har inte bett om
                // namnet. Kontot använder inte SoftDeletes, så namnet finns
                // alltid på en rad med `to_account_id`.
                'recipient_name' => $transfer->toAccount?->name,
            ])->all(),
            'items' => $this->items($container),
            'retainLevels' => self::RETAIN_LEVELS,
            'planNotice' => $this->featureNotice($entitlements, $container->account, $translator),
            'can' => [
                'transfer' => Gate::forUser($request->user())->allows('transfer', $container),
            ],
        ]);
    }

    /**
     * POST /containers/{container}/transfer — initierar överlåtelsen, 302
     * tillbaka till sidan.
     *
     * Ordningen är API:ets (39a § Beslut 8): `Gate::authorize()` först, sedan
     * plangrinden — en användare som inte får göra saken alls ska få 403, inte
     * en reklamskylt för Pro.
     *
     * **Ett domänfel blir ett formulärfel, aldrig en JSON-kropp.**
     * `ApiException` svarar `{"error":{"code":…}}` var den än kastas, också
     * från en Inertia-kontroller, så dubblettspärren fångas och formuleras av
     * App\Support\Frontend\ApiErrorTranslator. Nyckeln är `transfer` och inte
     * ett fältnamn: felet handlar om pärmens tillstånd och inte om vad
     * användaren skrev.
     */
    public function store(
        StoreOwnershipTransferRequest $request,
        Container $container,
        Entitlements $entitlements,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('transfer', $container);

        $this->assertFeature($entitlements, $container->account, $translator);

        try {
            $this->createTransfer($request, $container);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['transfer' => $translator->message($e)]);
        }

        return redirect()
            ->route('containers.transfer', $container)
            ->with('status', 'transfer-created');
    }

    /**
     * DELETE /containers/{container}/transfer/{transfer} — drar tillbaka
     * överlåtelsen, 302 tillbaka till sidan.
     *
     * `revoked` är avsändarens ånger och raden raderas aldrig
     * ([[Konton och åtkomst]] § ownership_transfer, Beslut 4): en överlåtelse
     * som skickats till fel adress måste gå att stoppa, och historiken står
     * kvar i listan.
     *
     * Bara en `pending`-rad kan dras tillbaka, och villkoret sitter i
     * UPDATE-satsen och inte i ett `if` före ett `save()` — samma
     * engångsspärr som App\Http\Controllers\Api\OwnershipTransferController::
     * destroy() och AcceptInvitation använder. En utgången rad går däremot att
     * dra tillbaka: kolumnen står fortfarande på `pending` (utgången härleds
     * ur `created_at`), och att städa bort en glömd begäran ur listan är
     * precis vad avsändaren vill kunna göra.
     *
     * `{transfer}` löses av `scopeBindings()` i routes/web.php, så en ULID ur
     * en annan pärm blir 404 innan den här metoden körs.
     */
    public function destroy(
        Container $container,
        OwnershipTransfer $transfer,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        Gate::authorize('transfer', $container);

        $revoked = OwnershipTransfer::query()
            ->whereKey($transfer->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        if ($revoked !== 1) {
            throw ValidationException::withMessages([
                'transfer' => $translator->message(ApiException::make('transfer.not_pending', [], 422)),
            ]);
        }

        return redirect()
            ->route('containers.transfer', $container)
            ->with('status', 'transfer-revoked');
    }

    /**
     * GET /transfers — mottagarens inkorg (Beslut 5 och 6).
     *
     * Listan är `inboxQuery()`: rader som är `pending`, inte utgångna, och som
     * antingen pekar på ett av användarens konton eller på hennes VERIFIERADE
     * adress. Ingenting annat, aldrig — en overifierad adress ger ingen träff,
     * och en rad någon annan har med att göra syns inte.
     *
     * **Konsekvenserna visas innan knappen** (Beslut 6), och de kommer med
     * raden: `from_account_name` är "från vilket konto", `item_count` och
     * `excluded_count` är "hur många följer med och hur många undantas", och
     * `retain_access_level` är åtkomsten avsändaren behåller. De tolv
     * månaderna Pro är en fast mening i vyn. Allt det är presentation BREDVID
     * `OwnershipTransferResource` — resursen är `/api`:s kontrakt och
     * `app/Http/Resources/**` ligger utanför omfångsrutan, och `from_account`
     * finns inte i den.
     *
     * `item_count` räknas i EN fråga för alla rader, inte en per rad: antalet
     * items är containerns, och en mottagare med flera begäranden ska inte
     * kosta en fråga per begäran.
     *
     * **Antalet undantagna räknas på LEVANDE items** (se `excludedCounts()`):
     * en undantagen post som mjukraderats mellan initieringen och accepten
     * följer varken med eller undantas, och utan den kontrollen hade "följer
     * med" kunnat bli ett negativt tal på en sida vars hela uppgift är att
     * visa konsekvenserna.
     */
    public function incoming(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $transfers = $this->inboxQuery($user)
            ->with(['container', 'fromAccount', 'toAccount'])
            ->orderByDesc('created_at')
            ->get();

        $itemCounts = $this->itemCounts($transfers);
        $excludedCounts = $this->excludedCounts($transfers);

        return Inertia::render('Transfers/Index', [
            'transfers' => $transfers->map(fn (OwnershipTransfer $transfer): array => [
                ...OwnershipTransferResource::make($transfer)->resolve($request),
                'from_account_name' => $transfer->fromAccount?->name,
                'item_count' => $itemCounts[$transfer->container_id] ?? 0,
                'excluded_count' => $excludedCounts[$transfer->ulid] ?? 0,
            ])->all(),
        ]);
    }

    /**
     * POST /transfers/{transfer}/accept — 302 till pärmlistan.
     *
     * Urvalet är `recipientQuery()` UTAN status- och tidsvillkor, exakt som
     * `/api`:s accept (39b § Beslut 1): en rad som väl är mottagarens men inte
     * längre `pending` ska ge `transfer.not_pending`, och en utgången rad
     * `transfer.expired` — inte försvinna som 404. En rad som inte pekar på
     * användaren är däremot osynlig: 404, aldrig 403 (Beslut 8).
     *
     * **`AcceptOwnershipTransfer` rörs inte.** Den äger transaktionen —
     * plankontrollen, flytten av containern och förbrukningen, återkallandet
     * av åtkomsterna och de tolv månaderna Pro — och det här är skalet
     * [[ADR-0024 Tunna controllers och actions]] beskriver.
     *
     * **Kvotfelet blir en mening med gräns och värde** (Beslut 6).
     * `quota.containers_exceeded` och `quota.storage_exceeded` bär `limit` och
     * `used` (`*_bytes` formaterade till läsbara tal av ApiErrorTranslator),
     * och meningen säger dem — samma form som varje annat kvotfel på webben
     * (60a § Beslut 5 och 6). Ingenting flyttas när den kastar: hela
     * transaktionen rullas tillbaka.
     *
     * Den nya pärmen blir aktiv, av samma skäl som efter en accepterad inbjudan
     * (55b § Beslut 4): att just ha fått en pärm ska innebära att landa i den.
     */
    public function accept(
        AcceptOwnershipTransferRequest $request,
        OwnershipTransfer $transfer,
        AcceptOwnershipTransfer $acceptOwnershipTransfer,
        ApiErrorTranslator $translator,
        ActiveContainer $activeContainer,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $incoming = $this->recipientQuery($user)
            ->whereKey($transfer->getKey())
            ->first();

        if (! $incoming instanceof OwnershipTransfer) {
            abort(404);
        }

        try {
            $container = $acceptOwnershipTransfer->handle(
                $transfer,
                $this->resolveReceiver($transfer, $request),
            );
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['transfer' => $translator->message($e)]);
        }

        $activeContainer->set($user, $container);

        return redirect()
            ->route('containers.index')
            ->with('status', 'transfer-accepted');
    }

    /**
     * POST /transfers/{transfer}/reject — 302 tillbaka till inkorgen.
     *
     * Urvalet är `inboxQuery()`, alltså exakt samma som listan visar: en rad
     * som inte syns i inkorgen går inte heller att avvisa (Beslut 8). En
     * redan besvarad rad är därför 404 här, medan accept — som använder det
     * vidare urvalet — svarar `transfer.not_pending`. Skillnaden är API:ets,
     * och den speglas rakt av.
     *
     * **Avslag är slutgiltigt och raden står kvar** (Beslut 7). Flippen är en
     * villkorad UPDATE, aldrig läs-följt-av-skriv, och det finns ingen väg
     * tillbaka som knapp: en ny överlåtelse måste skickas av avsändaren.
     */
    public function reject(
        Request $request,
        OwnershipTransfer $transfer,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $incoming = $this->inboxQuery($user)
            ->whereKey($transfer->getKey())
            ->first();

        if (! $incoming instanceof OwnershipTransfer) {
            abort(404);
        }

        $rejected = OwnershipTransfer::query()
            ->whereKey($transfer->getKey())
            ->where('status', 'pending')
            ->update(['status' => 'rejected']);

        if ($rejected !== 1) {
            throw ValidationException::withMessages([
                'transfer' => $translator->message(ApiException::make('transfer.not_pending', [], 422)),
            ]);
        }

        return redirect()
            ->route('transfers.index')
            ->with('status', 'transfer-rejected');
    }

    /**
     * Initieringen: dubblettspärren, raden och mottagarvägens notifiering —
     * samma skrivning som `Api\OwnershipTransferController::store()` gör, se
     * klassens docblock om varför den står här en andra gång.
     *
     * Dubblettspärren frågar efter en `pending`-rad som inte gått ut. En
     * utgången, avvisad eller tillbakadragen rad blockerar inget — att begära
     * igen efter ett nej ska gå — och utgången läses ur `created_at` + TTL och
     * inte ur `status`, för kolumnen flippas aldrig (39a § Beslut 10).
     *
     * `to_account` löses upp till id, `to_email` normaliseras till gemener
     * innan den lagras, och alla kolumner sätts explicit — aldrig via
     * massildelning (`OwnershipTransfer` har tom `#[Fillable]`).
     */
    private function createTransfer(StoreOwnershipTransferRequest $request, Container $container): void
    {
        $existing = $container->transfers()
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subDays(OwnershipTransfer::TTL_DAYS))
            ->first();

        if ($existing instanceof OwnershipTransfer) {
            throw ApiException::make('transfer.already_pending', ['transfer' => $existing->ulid], 422);
        }

        $transfer = new OwnershipTransfer;
        $transfer->container_id = $container->id;
        $transfer->from_account_id = $container->account_id;
        $transfer->status = 'pending';
        $transfer->excluded_item_ids = $request->validated('excluded_items') ?? [];
        $transfer->retain_access_level = $request->validated('retain_access_level');
        $transfer->initiated_by_user_id = $request->user()->id;

        $toAccount = null;
        $toEmail = null;

        if ($request->validated('to_account') !== null) {
            $toAccount = Account::query()->where('ulid', $request->validated('to_account'))->firstOrFail();
            $transfer->to_account_id = $toAccount->id;
            $transfer->to_email = null;
        } else {
            $toEmail = mb_strtolower((string) $request->validated('to_email'));
            $transfer->to_account_id = null;
            $transfer->to_email = $toEmail;
        }

        $transfer->save();

        // Mottagarvägen avgör notifieringen (39a § Beslut 12).
        if ($toAccount instanceof Account) {
            $this->notifyAccountMembers($transfer, $toAccount, $container);
        } else {
            $this->sendOnDemandMail($toEmail, $container);
        }
    }

    /**
     * En `transfer.requested`-notis per medlem i det mottagande kontot, genom
     * App\Actions\Notification\CreateNotification — den enda vägen in i
     * `notification`, som respekterar preferenser och tysta timmar och skapar
     * leveransraderna för kön.
     *
     * `dedupe_key` bär transfer och mottagare: skulle store() anropas två
     * gånger (efter att den första raden hunnit skapas men innan svaret)
     * skapar CreateNotification ingen andra notisrad.
     */
    private function notifyAccountMembers(OwnershipTransfer $transfer, Account $toAccount, Container $container): void
    {
        foreach ($toAccount->users as $recipient) {
            app(CreateNotification::class)->handle(
                type: NotificationModel::TYPE_TRANSFER_REQUESTED,
                account: $toAccount,
                user: $recipient,
                container: $container,
                payload: ['container' => $container->name],
                dedupeKey: "transfer.requested:{$transfer->ulid}:{$recipient->ulid}",
            );
        }
    }

    /**
     * On-demand-mejlet till en adress utan konto. Inget `notification`-rad
     * skapas, och mejlet bär ingen token och ingen länk med hemlighet: det
     * pekar på `/transfers`, sökvägen den här sidan svarar på, och ber
     * mottagaren skapa ett konto med just den adressen och verifiera den.
     */
    private function sendOnDemandMail(string $email, Container $container): void
    {
        $url = rtrim((string) config('app.url'), '/').'/transfers';

        NotificationFacade::route('mail', $email)
            ->notify(new OwnershipTransferNotification($url, $container));
    }

    /**
     * `AcceptOwnershipTransferRequest` har olika regler beroende på raden, och
     * den här metoden svarar på samma fråga som den: vilket konto tar emot?
     *
     * Är `to_account_id` satt är svaret givet — requesten har redan bevisat
     * att en eventuell `to_account` i kroppen pekar på samma konto. Är bara
     * `to_email` satt måste kroppen bära `to_account`, och requesten har
     * bevisat att det är ett konto användaren är medlem i.
     */
    private function resolveReceiver(
        OwnershipTransfer $transfer,
        AcceptOwnershipTransferRequest $request,
    ): Account {
        if ($transfer->to_account_id !== null) {
            return Account::query()->findOrFail($transfer->to_account_id);
        }

        return Account::query()
            ->where('ulid', $request->validated('to_account'))
            ->firstOrFail();
    }

    /**
     * Mottagarens inkorg — den ENDA formuleringen av "vad som är inkommande
     * för den här användaren" i den här kontrollern, delad av `incoming` och
     * `reject`. Villkoren: `status = 'pending'`, inte utgången, och containern
     * lever (SoftDeletes — en mjukraderad pärm ska inte erbjudas till övertag).
     *
     * @return Builder<OwnershipTransfer>
     */
    private function inboxQuery(User $user): Builder
    {
        return $this->recipientQuery($user)
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subDays(OwnershipTransfer::TTL_DAYS));
    }

    /**
     * Mottagarvägen, utbruten ur inboxQuery(): en rad ÄR användarens när
     * antingen `to_account_id` finns bland hennes konton eller `to_email` är
     * lika med hennes VERIFIERADE adress. En overifierad adress ska inte se
     * begäran ([[ADR-0003 Åtkomstmodell]]) — verifieringen är hela
     * identitetsbeviset när ingen token finns.
     *
     * Inga status- eller tidsvillkor: accept behöver hitta även rader som inte
     * längre går att acceptera, för att kunna svara `transfer.not_pending` och
     * `transfer.expired` i stället för 404.
     *
     * @return Builder<OwnershipTransfer>
     */
    private function recipientQuery(User $user): Builder
    {
        $accountIds = $user->accounts->pluck('id');

        return OwnershipTransfer::query()
            ->whereHas('container')
            ->where(function (Builder $query) use ($user, $accountIds): void {
                $query->whereIn('to_account_id', $accountIds);

                if ($user->hasVerifiedEmail()) {
                    $query->orWhere('to_email', mb_strtolower($user->email));
                }
            });
    }

    /**
     * Plangrinden som ett formulärfel, med nyckeln `plan`: felet handlar om
     * kontots plan och inte om vad användaren skrev — samma val som `quota` i
     * App\Http\Controllers\ContainerController::store(). Ordningen mot
     * `Gate::authorize()` är anroparens ansvar (27a § Beslut 3).
     *
     * @throws ValidationException
     */
    private function assertFeature(Entitlements $entitlements, Account $account, ApiErrorTranslator $translator): void
    {
        $notice = $this->featureNotice($entitlements, $account, $translator);

        if ($notice !== null) {
            throw ValidationException::withMessages(['plan' => $notice]);
        }
    }

    /**
     * Meningen för funktionsgrinden, eller `null` om kontots plan har den.
     *
     * Frågan ställs till SAMMA metod som POST grindar med, och ett undantag
     * betyder nej — att i stället läsa `$account->planLimit('ownership_transfer')
     * !== false` hade varit en andra formulering av samma regel, och de två
     * hade glidit isär den dag `assertFeature()` ändras. Sidan skickar svaret
     * som prop och `assertFeature()` ovan som fältfel, så sidan och servern
     * visar samma mening.
     *
     * `data.feature` är en KOD (`ownership_transfer`) och inte ett namn, så
     * den översätts i två steg — först till ett namn, sedan in i meningen —
     * precis som App\Http\Controllers\WebhookEndpointController::planMessage()
     * gör. Ett meddelande som slänger bort `data` är sämre än felkoden det
     * ersatte.
     */
    private function featureNotice(Entitlements $entitlements, Account $account, ApiErrorTranslator $translator): ?string
    {
        try {
            $entitlements->assertFeature($account, 'ownership_transfer');
        } catch (ApiException $e) {
            $feature = (string) ($e->data()['feature'] ?? '');

            return $translator->message(ApiException::make($e->errorCode(), [
                'feature' => (string) trans('ui.error.plan.feature_name.'.$feature),
            ]));
        }

        return null;
    }

    /**
     * Pärmens levande items — undantagsvalet i formuläret, som `{ulid, name}`
     * och ingenting mer. EN fråga, ingen paginering och ingen sökning: listan
     * är pärmens innehåll och plantaket sätter taket för hur lång den kan bli.
     *
     * Ingen `withTrashed()`: ett mjukraderat item kan inte undantas, och
     * `StoreOwnershipTransferRequest` avvisar det — samma lista och samma
     * villkor som inbjudningsformuläret (55b).
     *
     * @return list<array{ulid: string, name: string}>
     */
    private function items(Container $container): array
    {
        return $container->items()
            ->orderBy('name')
            ->get(['ulid', 'name'])
            ->map(fn (Item $item): array => ['ulid' => $item->ulid, 'name' => $item->name])
            ->values()
            ->all();
    }

    /**
     * Container-id → antal levande items, i EN fråga för alla rader i inkorgen.
     * SoftDeletes' globala scope gäller genom `Item::query()`, så mjukraderade
     * items räknas inte — de följer inte med till mottagaren.
     *
     * @param  Collection<int, OwnershipTransfer>  $transfers
     * @return array<int, int>
     */
    private function itemCounts(Collection $transfers): array
    {
        $containerIds = $transfers->pluck('container_id')->unique()->values()->all();

        if ($containerIds === []) {
            return [];
        }

        return Item::query()
            ->whereIn('container_id', $containerIds)
            ->selectRaw('container_id, COUNT(*) as item_count')
            ->groupBy('container_id')
            ->pluck('item_count', 'container_id')
            ->map(fn ($antal): int => (int) $antal)
            ->all();
    }

    /**
     * Transfer-ULID → antal undantagna items som fortfarande LEVER, i EN fråga
     * för hela inkorgen. `excluded_item_ids` är en lista av ULID:er som
     * bevisades peka på levande items i containern vid INITIERINGEN (39a
     * § Beslut 5), och en post som mjukraderats sedan dess lyfts inte ut av
     * accepten (App\Actions\OwnershipTransfer\AcceptOwnershipTransfer) — den
     * följer alltså varken med eller undantas.
     *
     * Unionen av ULID:erna är liten: det är de poster avsändaren valde bort.
     * `Item::query()` bär SoftDeletes' globala scope, så den raderade faller
     * bort av sig själv. Utan den här kontrollen hade `item_count` (levande
     * items i pärmen) och `excluded_count` (listans längd) kunnat beskriva
     * olika mängder, och kortets "följer med" blivit ett negativt tal — på en
     * sida vars hela uppgift är att visa konsekvenserna.
     *
     * @param  Collection<int, OwnershipTransfer>  $transfers
     * @return array<string, int>
     */
    private function excludedCounts(Collection $transfers): array
    {
        $ulids = $transfers->pluck('excluded_item_ids')
            ->flatten()
            ->filter()
            ->unique()
            ->values()
            ->all();

        $levande = $ulids === []
            ? []
            : Item::query()->whereIn('ulid', $ulids)->pluck('ulid')->all();

        $antal = [];

        foreach ($transfers as $transfer) {
            $antal[$transfer->ulid] = count(array_intersect($transfer->excluded_item_ids ?? [], $levande));
        }

        return $antal;
    }
}

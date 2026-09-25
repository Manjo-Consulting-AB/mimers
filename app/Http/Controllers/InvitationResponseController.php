<?php

namespace App\Http\Controllers;

use App\Actions\Invitation\AcceptInvitation;
use App\Actions\Invitation\RejectInvitation;
use App\Exceptions\Api\ApiException;
use App\Http\Requests\Invitation\InvitationTokenRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use App\Support\Frontend\ApiErrorTranslator;
use App\Support\Invitation\PendingInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mottagarsidan i webben — mejlets landningssida, accept och avvisande, se
 * issue 55b § Beslut 1, 2, 3 och 4. API-motsvarigheten är
 * App\Http\Controllers\Api\InvitationResponseController; kontrollkedjan
 * innan något händer är App\Http\Requests\Invitation\InvitationTokenRequest
 * och App\Support\Invitation\PendingInvitation::assert(), och de delas rakt
 * av.
 *
 * **Sedan issue 131 har sidan en andra väg in, och den är ingen andra
 * kontroll.** Ligger inget token i sessionen visar `waiting()` i stället
 * användarens EGNA väntande inbjudningar — uppslagna på hennes verifierade
 * adress och inte på en hemlighet — och accept och avvisande går då på
 * inbjudans `ulid` i stället för på tokenet. Tokenvägen är oförändrad:
 * ligger ett token där gäller § Beslut 3 som förut. Det är samma mönster som
 * App\Http\Controllers\OwnershipTransferController, och av samma skäl —
 * en inbjudan ger läsrätt till en container, och en rad som inte är
 * användarens ska vara osynlig (404) i stället för att bekräftas med 403.
 *
 * **`invitation.received` skrivs fortfarande inte.** Klockan ritar en rad per
 * väntande inbjudan direkt ur `invitation`
 * (App\Http\Middleware\HandleInertiaRequests::pendingInvitations()), och om
 * konstanten ska bort eller börja skrivas står som fråga i [[Tankar]]
 * § Öppet.
 *
 * **Tokenet lämnar URL:en direkt** (§ Beslut 2). `open()` lägger det i
 * sessionen och omdirigerar till `/invitations`; ingenting renderas på
 * `/invitations/{token}`. Ett token i en URL hamnar i webbläsarhistoriken, i
 * `Referer` och i varje åtkomstlogg på vägen — samma skäl som gör att
 * `/api/invitations/accept` tar det i kroppen (issue 10b § Beslut 1). Mejlets
 * länk kan inte undvika det, men sidan behöver inte behålla det. Sessionen
 * bär tokenet över registreringen och inloggningen, så mottagaren — som
 * oftast måste skapa konto först — inte behöver hitta tillbaka till mejlet;
 * kroppen är det som skickas in, och accept- och avvisa-formulären bär det i
 * ett dolt fält.
 *
 * `SESSION_KEY` stavas bara här, och glöms när inbjudan accepterats,
 * avvisats eller visat sig vara ogiltig. Samma form som
 * App\Support\Frontend\ActiveContainer, som äger sin nyckel på samma sätt —
 * klassen kunde inte läggas under `app/Support/` eftersom issue 55b § Omfång
 * inte listar den katalogen.
 *
 * **Accept anropar App\Actions\Invitation\AcceptInvitation oförändrad**
 * (§ Beslut 4). Verifieringskravet, engångsspärren, dubblettspärren och det
 * frusna ägarkontot bor där och prövas på samma sätt som i `/api` — och
 * ingen policy anropas, för den som bär ett giltigt token för sin egen adress
 * ÄR behörig (issue 10b § Beslut 12).
 *
 * `open()` och `show()` ligger medvetet UTANFÖR `auth`-gruppen i
 * routes/web.php: den här är den enda ytan i M10 där en utloggad besökare ska
 * mötas av något annat än inloggningssidan.
 */
class InvitationResponseController extends Controller
{
    /**
     * Sessionsnyckeln för inbjudningens token. Stavas bara här.
     */
    public const SESSION_KEY = 'pending_invitation_token';

    /**
     * GET /invitations/{token} — 302 till `/invitations`, ingenting renderas.
     *
     * **Tokenet sparas bara om det KAN vara ett token.** Formen är
     * `Str::random(64)`, alltså 64 tecken ur `[A-Za-z0-9]`. Kontrollen finns
     * för ett verkligt fall och inte för symmetrins skull: efter en lyckad
     * inloggning skickar `redirect()->intended()` användaren tillbaka till
     * den URL som nekades, och för en gäst som postade till
     * `/invitations/accept` är det en GET på samma sökväg — som matchar den
     * här rutten med `token = "accept"`. Utan kontrollen hade den skrivit
     * över den inbjudan sessionen redan bar, och mottagaren hade mötts av
     * "inbjudan går inte att använda" i stället för av sin inbjudan.
     *
     * Ingen `throttle` (§ Beslut 1): tokenet är 64 tecken ur
     * `random_bytes()`, och entropin ÄR skyddet — samma avvägning som
     * App\Support\Auth\MagicLinkBroker § Beslut 4 skriver ut för mejllänken.
     */
    public function open(Request $request, string $token): RedirectResponse
    {
        if (preg_match('/^[A-Za-z0-9]{64}$/', $token) === 1) {
            $request->session()->put(self::SESSION_KEY, $token);
        }

        return redirect()->route('invitations.show');
    }

    /**
     * GET /invitations — landningssidan, som renderar EXAKT ett av sex
     * tillstånd (§ Beslut 3, och `pending` sedan issue 131):
     *
     * - `guest`       — utloggad, och tokenet är giltigt. Containerns namn, vem
     *                   som bjöd in och nivån, plus vägarna till inloggning
     *                   och registrering.
     * - `unverified`  — inloggad mottagare med overifierad adress.
     * - `ready`       — inloggad, verifierad mottagare. Här bor accept- och
     *                   avvisa-formulären; utan det tillståndet hade en
     *                   inbjudan gått ut utan att kunna besvaras.
     * - `pending`     — inloggad, verifierad, och INGET token i sessionen.
     *                   Listan över hennes väntande inbjudningar.
     * - `mismatch`    — inloggad med en annan adress än inbjudans.
     * - `unavailable` — utgången, redan besvarad eller okänt token.
     *
     * **De två sista går inte att skilja åt** och formuleras lika: ett "den
     * inbjudan finns inte" mot ett "den är redan accepterad" är en orakelyta
     * mot giltiga token. Därför är de ETT tillstånd och en mening.
     *
     * Tillståndet avgörs av den delade InvitationTokenRequest::invitation()
     * — samma uppslag, samma `pending`-kontroll, samma utgångskontroll och
     * samma adressjämförelse som accept- och avvisa-vägarna använder. Ingen
     * av dem formuleras om här. Ligger inget token i sessionen är svaret i
     * stället `waiting()`.
     */
    public function show(Request $request, PendingInvitation $pendingInvitation): Response
    {
        $token = $request->session()->get(self::SESSION_KEY);

        if (! is_string($token) || $token === '') {
            return $this->waiting($request, $pendingInvitation);
        }

        // Tokenet ligger i sessionen och inte i kroppen, så den delade
        // FormRequesten får en kopia av requesten med tokenet isatt —
        // `createFrom()` bär med sig user-resolvern och sessionen, och
        // `invitation()` läser `token` ur kroppen precis som på /api.
        $tokenRequest = InvitationTokenRequest::createFrom($request)->merge(['token' => $token]);

        try {
            $invitation = $tokenRequest->invitation();
        } catch (ApiException $e) {
            return $this->invalid($request, $token, $e);
        }

        /** @var User $user */
        $user = $request->user();

        if (! $user->hasVerifiedEmail()) {
            // Samma kod AcceptInvitation kastar när den nekar, och samma
            // villkor (`hasVerifiedEmail()`) — verifieringskravet bor i
            // App\Actions\Invitation\AcceptInvitation § Beslut 6 och
            // formulerades som ett villkor och inte som `verified`-middleware
            // just för att mottagaren ska få veta VARFÖR hon nekas.
            return $this->page('unverified', $this->preview($invitation));
        }

        return $this->page('ready', $this->preview($invitation), $token);
    }

    /**
     * Läget utan token i sessionen — listan över användarens väntande
     * inbjudningar, se issue 131 och [[M20 Kontot]] § 131.
     *
     * **Listan byggs på identitet och inte på en hemlighet.** Uppslaget är
     * App\Support\Invitation\PendingInvitation::forUser() — användarens
     * VERIFIERADE adress, och ingenting annat. Verifieringen är vad som
     * ersätter tokenet ([[ADR-0003 Åtkomstmodell]]): tokenet bevisar att
     * mottagaren når brevlådan, och en verifierad adress som är lika med
     * inbjudans bevisar samma sak. Därför ritas ingen lista alls för en
     * overifierad användare — tillståndet blir `unverified`, och AppLayouts
     * banner säger varför.
     *
     * **En gäst får det neutrala beskedet**, precis som förut: en utloggad
     * besökare på `/invitations` har varken token eller adress, och
     * `unavailable` är det svar sidan redan gav utan token. Rutten ligger
     * kvar utanför `auth`-gruppen av skälet som står i routes/web.php.
     *
     * **`invitations` är en tom LISTA och inte `null` för en verifierad
     * användare utan väntande inbjudningar** — samma skillnad som klockans
     * `notifications` gör: den ena betyder "ingenting väntar", den andra
     * "ingen lista finns". Vyn ritar tomtillståndet för den första och
     * ingenting för den andra.
     *
     * Bara det vyn behöver följer med: containerns namn, inbjudarens namn och
     * nivån. Ingen `InvitationResource` — den är `/api`:s kontrakt och listar
     * `email`, som aldrig får nå en sida (se `preview()`), och
     * `app/Http/Resources/**` ligger utanför issue 131:s omfång.
     */
    private function waiting(Request $request, PendingInvitation $pendingInvitation): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $this->page('unavailable');
        }

        if (! $user->hasVerifiedEmail()) {
            return $this->page('unverified');
        }

        $invitations = $pendingInvitation->forUser($user)
            ->with(['container', 'invitedBy'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $invitation): array => [
                'ulid' => $invitation->ulid,
                'container' => $invitation->container->name,
                'inviter' => $invitation->invitedBy->name,
                'level' => $invitation->level,
            ])
            ->values()
            ->all();

        // Namngivna argument: de två mittersta propparna hör till tokenvägen,
        // och `page('pending', null, null, $invitations)` hade tvingat läsaren
        // att räkna positioner för att se det.
        return $this->page(state: 'pending', invitations: $invitations);
    }

    /**
     * POST /invitations/accept — 302 till `/containers`.
     *
     * `auth` krävs och verifierad adress krävs, men ingenting mer (§ Beslut
     * 4). Ingen policy: `InvitationTokenRequest::invitation()` har redan
     * bevisat att tokenet hör till den inloggade adressen, och den som bär
     * ett giltigt token för sin egen adress ÄR behörig.
     *
     * Att just ha fått en container ska innebära att landa i den, så den nya
     * containern blir aktiv (§ Beslut 4) — samma tre steg som issue 54 § Beslut 6
     * räknar upp, genom App\Support\Frontend\ActiveContainer::set().
     *
     * `ApiException` får aldrig nå webbläsaren som JSON, vare sig den kommer
     * ur uppslaget eller ur actionen: den blir ett formulärfel på nyckeln
     * `invitation`, som vyn visar ovanför formuläret.
     */
    public function accept(
        InvitationTokenRequest $request,
        AcceptInvitation $acceptInvitation,
        ActiveContainer $activeContainer,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $container = $acceptInvitation->handle($request->invitation(), $user);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['invitation' => $translator->message($e)]);
        }

        $request->session()->forget(self::SESSION_KEY);

        $activeContainer->set($user, $container);

        return redirect()
            ->route('containers.index')
            ->with('status', 'invitation-accepted');
    }

    /**
     * POST /invitations/reject — 302 till startsidan.
     *
     * Kräver inloggning men INTE verifierad adress (issue 10b § Beslut 7):
     * att tacka nej ger ingen behörighet, och att tvinga fram en verifiering
     * för att bli av med ett mejl vore fel väg. Autentisering och
     * adressmatchning krävs fortfarande — de prövas av
     * `InvitationTokenRequest::invitation()`.
     *
     * Sedan issue 111 bor flippen OCH `invitation.rejected` i
     * App\Actions\Invitation\RejectInvitation, som `/api` anropar på samma
     * sätt. Fram till dess stod skrivningen här med motiveringen att den var
     * "statusflippen och ingenting mer" och att tröskeln i [[ADR-0024 Tunna
     * controllers och actions]] inte var nådd — raden i handlingens
     * transaktion är det som ändrade det. Engångsspärren sitter alltjämt i
     * UPDATE-satsen och inte i ett `if` före ett `save()`.
     */
    public function reject(
        InvitationTokenRequest $request,
        RejectInvitation $rejectInvitation,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        try {
            $rejectInvitation->handle($request->invitation(), $request->user());
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['invitation' => $translator->message($e)]);
        }

        $request->session()->forget(self::SESSION_KEY);

        return redirect()
            ->route('welcome')
            ->with('status', 'invitation-rejected');
    }

    /**
     * POST /invitations/{invitation}/accept — 302 till `/containers`.
     *
     * Acceptvägen för listan (issue 131). Skillnaden mot `accept()` ovan är
     * UPPSLAGET och ingenting annat: raden hittas på sin ULID i stället för
     * på tokenet i kroppen, och kontrollerna är
     * App\Support\Invitation\PendingInvitation::assert() — samma fyra steg,
     * samma felkoder, ingen avskrift. `AcceptInvitation` anropas oförändrad,
     * så åtkomsten blir exakt den tokenvägen ger, och containern blir aktiv av
     * samma skäl som där (§ Beslut 4).
     *
     * **Allt `assert()` säger nej till blir `404`.** En inbjudan som inte är
     * användarens, en som redan besvarats, en som dragits tillbaka och en som
     * gått ut är alla OSYNLIGA — ett gissat `ulid` ska inte kunna skilja "finns
     * inte" från "finns, men är inte din", precis som tokenvägens
     * `unavailable` inte skiljer dem åt (§ Beslut 3). Det är samma svar som
     * App\Http\Controllers\OwnershipTransferController::accept() ger en rad
     * som inte pekar på användaren (issue 67b § Beslut 8).
     *
     * **Efter `assert()` är felkoderna tillbaka.** Det som återstår kan bara
     * hända mellan kontrollen och skrivningen — engångsspärren i
     * AcceptInvitation, eller en adress som hann bli overifierad — och de
     * felen blir formulärfel som på tokenvägen, aldrig en rå JSON-kropp.
     *
     * Sessionen rörs INTE: det finns inget token att glömma, och ett token
     * som ligger där tillhör tokenvägen. Är det samma inbjudan visar
     * `/invitations` `unavailable` nästa gång, och det är rätt svar — den är
     * besvarad.
     */
    public function acceptPending(
        Request $request,
        Invitation $invitation,
        PendingInvitation $pendingInvitation,
        AcceptInvitation $acceptInvitation,
        ActiveContainer $activeContainer,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $invitation = $pendingInvitation->assert($invitation, $user);
        } catch (ApiException) {
            abort(404);
        }

        try {
            $container = $acceptInvitation->handle($invitation, $user);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['invitation' => $translator->message($e)]);
        }

        $activeContainer->set($user, $container);

        return redirect()
            ->route('containers.index')
            ->with('status', 'invitation-accepted');
    }

    /**
     * POST /invitations/{invitation}/reject — 302 tillbaka till `/invitations`.
     *
     * Avvisandet för listan (issue 131), och samma upplägg som `acceptPending()`
     * ovan: uppslaget är ULID:n, kontrollerna är
     * PendingInvitation::assert(), och allt den säger nej till blir `404`.
     * App\Actions\Invitation\RejectInvitation anropas oförändrad, så raden får
     * samma `status` som via token.
     *
     * **Verifierad adress krävs INTE för att tacka nej**, precis som på
     * tokenvägen (issue 10b § Beslut 7): att avvisa ger ingen behörighet.
     * Kravet ligger i `waiting()` och i den delade proppen, alltså på LISTAN —
     * en overifierad användare ser den inte, och har därför ingen ULID att
     * posta. Kan hon sin egen ULID får hon avvisa, och det är riktigt.
     *
     * **Tillbaka till listan och inte till startsidan.** Tokenvägen landar på
     * `/` därför att mottagaren kom från ett mejl och inte har någon sida att
     * återvända till; den som svarar ur listan står kvar i den, och
     * App\Http\Controllers\OwnershipTransferController::reject() gör samma val
     * av samma skäl.
     */
    public function rejectPending(
        Request $request,
        Invitation $invitation,
        PendingInvitation $pendingInvitation,
        RejectInvitation $rejectInvitation,
        ApiErrorTranslator $translator,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        try {
            $invitation = $pendingInvitation->assert($invitation, $user);
        } catch (ApiException) {
            abort(404);
        }

        try {
            $rejectInvitation->handle($invitation, $user);
        } catch (ApiException $e) {
            throw ValidationException::withMessages(['invitation' => $translator->message($e)]);
        }

        return redirect()
            ->route('invitations.show')
            ->with('status', 'invitation-rejected');
    }

    /**
     * Tillståndet när InvitationTokenRequest::invitation() sa nej.
     *
     * **Gästen är ett eget fall.** Adressjämförelsen är den SISTA kontrollen
     * i den delade kedjan, och en utloggad besökare har ingen adress att
     * jämföra med — kontrollen faller alltid ut som `email_mismatch`. Att nå
     * hit med den koden betyder alltså att allt före den passerade: containern
     * finns, raden är `pending` och tiden har inte gått ut. Det är precis vad
     * förhandsvisningen får byggas på.
     *
     * De övriga koderna delas i två: `email_mismatch` för en inloggad är sitt
     * eget tillstånd, och `expired`, `not_pending` och `resource.not_found`
     * blir samma neutrala besked — de får inte gå att skilja åt (§ Beslut 3).
     * Bara de senare glömmer sessionen: en inbjudan som tillhör någon annan
     * är inte ogiltig, och den som loggar in med rätt adress ska kunna svara
     * på den utan att leta upp mejlet igen.
     */
    private function invalid(Request $request, string $token, ApiException $e): Response
    {
        $guest = $request->user() === null;

        if ($guest && $e->errorCode() === 'invitation.email_mismatch') {
            return $this->page('guest', $this->preview($this->row($token)));
        }

        if ($e->errorCode() === 'invitation.email_mismatch') {
            return $this->page('mismatch');
        }

        $request->session()->forget(self::SESSION_KEY);

        return $this->page('unavailable');
    }

    /**
     * Inbjudningsraden på sitt token. Anropas bara på grenar där
     * `invitation()` redan bevisat att raden finns — uppslaget är inte en
     * andra kontroll, och `firstOrFail()` är därför oåtkomlig.
     */
    private function row(string $token): Invitation
    {
        return Invitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->firstOrFail();
    }

    /**
     * Det förhandsvisningen får visa: containerns namn, vem som bjöd in och
     * nivån.
     *
     * Containerns namn och inbjudarens namn visas OCKSÅ för en gäst, och det är
     * ingen ny uppgift: App\Notifications\InvitationNotification skriver ut
     * containerns namn i både ämnesrad och brödtext, och den som har länken har
     * fått mejlet. **Adressen inbjudan gäller visas däremot aldrig** — den vet
     * mottagaren redan, och en bärare som inte är mottagaren ska inte få veta
     * den.
     *
     * @return array{container: string, inviter: string, level: string}
     */
    private function preview(Invitation $invitation): array
    {
        return [
            'container' => $invitation->container->name,
            'inviter' => $invitation->invitedBy->name,
            'level' => $invitation->level,
        ];
    }

    /**
     * @param  array{container: string, inviter: string, level: string}|null  $invitation
     * @param  list<array{ulid: string, container: string, inviter: string, level: string}>|null  $invitations
     */
    private function page(string $state, ?array $invitation = null, ?string $token = null, ?array $invitations = null): Response
    {
        return Inertia::render('Invitations/Show', [
            'state' => $state,
            'invitation' => $invitation,
            // Tokenet skickas bara till det tillstånd som HAR ett formulär att
            // lägga det i. Ett token i en prop är ett token i HTML:en, och de
            // övriga tillstånden har ingen användning för det.
            'token' => $token,
            // Listan skickas bara till `pending`. Den bär ULID:n, och de har
            // bara det tillståndet någon användning för — samma regel som för
            // tokenet ovan.
            'invitations' => $invitations,
        ]);
    }
}

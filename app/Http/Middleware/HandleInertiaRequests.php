<?php

namespace App\Http\Middleware;

use App\Actions\Item\ListFavorites;
use App\Http\Resources\AccountResource;
use App\Http\Resources\AuthUserResource;
use App\Models\Account;
use App\Models\Container;
use App\Models\Invitation;
use App\Models\Item;
use App\Models\Loan;
use App\Models\Notification;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use App\Support\Frontend\ActiveContainer;
use App\Support\Invitation\PendingInvitation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;
use Inertia\Inertia;
use Inertia\Middleware;

/**
 * De delade propsen — det enda som når varje webbsida, se issue 51
 * § Beslut 2 och 3.
 *
 * Nio nycklar, och ingen av dem byggs för hand: `auth.user` och
 * `auth.accounts` kommer ur samma API Resource-klasser som `/api` använder
 * ([[ADR-0021 Frontendteknik]] § "Inertia-props renderas ur samma API
 * Resource-klasser som /api"), `activeContainer` ur
 * App\Support\Frontend\ActiveContainer och `flash.status` ur sessionen.
 *
 * **`favorites` kom med issue 106** — sidopanelens `FAVORITER`-sektion,
 * se [[M17 Designsystemet]] § 106 och [[ADR-0042 Designsystemet]]
 * § Konsekvenser. Listan byggs av App\Actions\Item\ListFavorites, som
 * filtrerar den genom ResolveItemScope som varje annan listning: en favorit
 * användaren förlorat åtkomsten till försvinner i stället för att bli en
 * trasig länk, och ingenting i svaret berättar hur många som föll bort
 * (issue 73 § Beslut 6).
 *
 * Raden bär `name` och `url` och ingenting mer. Adressen byggs HÄR och inte i
 * JavaScript: den innehåller två ULID:n som bara servern känner, och skalets
 * egen regel är att en sådan URL skickas som prop (issue 51 § Beslut 7).
 * Ingen `ItemResource`: sektionen visar ett namn och en länk, och resurserna
 * under `app/Http/Resources/` rörs inte av den här issuen.
 *
 * **Notisklockan kom med issue 127**, och de två nycklarna är med flit olika
 * slags props. `unreadNotificationCount` är en SIFFRA och delas som allt
 * annat: den ritas i sidhuvudet på varje sida, och den kostar EN fråga per
 * sidladdning — indexet `(user_id, created_at)` finns för den.
 * `notifications` är LISTAN, och den är `Inertia::optional()`: den hämtas
 * först när klockan öppnas, genom en partiell omladdning av just den nyckeln,
 * och en vanlig sidladdning rör den aldrig. Skillnaden är hela poängen —
 * siffran är billig och behövs överallt, raderna är dyra och behövs sällan.
 *
 * **Klockan är ingen kanal.** Den läser `notification` som tabellen redan är
 * ([[Notiser]] § notification) och rör varken `notification_delivery`,
 * preferenserna eller de tysta timmarna — den som öppnar klockan har redan
 * fått sina mejl. De sex typer som faktiskt skrivs är de klockan visar ur
 * `notification` (issue 127 § Beslut).
 *
 * **Inbjudningarna kom med issue 131, och de läses ur `invitation`.** En
 * väntande inbjudan är ingen notisrad — `Notification::TYPE_INVITATION_RECEIVED`
 * finns som konstant men skrivs fortfarande av ingen kod — så klockan får sin
 * rad per väntande inbjudan ur inbjudningstabellen i stället, och
 * `pendingInvitations` är den andra optionala proppen. Raden ritar
 * `inbox.invitation.received` och länkar till `/invitations`, där svaret går.
 *
 * **Siffran räknar dem också**, och det är därför `unreadNotificationCount`
 * numera kan kosta TVÅ frågor på en sidladdning i stället för en: frågan går
 * på `invitation`s index `(email, status)`, och en inbjudan är något
 * användaren faktiskt behöver svara på. Att öppna klockan nollställer bara
 * notisraden av de två — `notifications_read_at` är en tidsstämpel på
 * användaren, och en inbjudan är obesvarad till dess att den besvarats, inte
 * till dess att den setts (App\Http\Controllers\NotificationInboxController).
 *
 * `locale` och `translations` kom med issue 52: locale sätts av
 * App\Http\Middleware\SetLocale, som ligger FÖRE den här middlewaren i
 * `web`-gruppen, så `App::getLocale()` är redan rätt när `share()` körs.
 * `translations` är `lang/{locale}/ui.php` och ingenting annat — notiser.php
 * och export.php är serverrenderat innehåll (mejl, ICS, PDF) och levereras
 * aldrig som prop.
 *
 * Allt är closures. Inertias middleware anropar share() på varje webbanrop
 * — även POST-rutter som bara svarar med en omdirigering — och löser först
 * senare upp det som faktiskt ska serialiseras, så en closure är skillnaden
 * mellan "frågan ställs när sidan renderas" och "frågan ställs på varje
 * anrop". `auth` är därför också lazy, inte bara `activeContainer`.
 *
 * `errors` delas medvetet INTE här. Inertia lägger redan sessionens
 * valideringsfel i propsen (Inertia\Middleware::share()), och en egen
 * version skuggar den — se issue 51 § Beslut 9.
 *
 * Middlewaren bara LÄSER kontexten och delar ut den (issue 83). Den som gör
 * en container till kontext är den kontroller som ÖPPNAR den —
 * App\Http\Controllers\ItemController::index(). Att skriva sessionstillstånd
 * här hade gjort utdelningen ordningsberoende och tvingat skalet att känna
 * igen ett ruttnamn, och då hade en framtida rutt in i containern satt
 * kontexten tyst utan att något test i närheten blev rött.
 */
class HandleInertiaRequests extends Middleware
{
    /** Klockan visar de tjugo senaste — se klassens docblock (issue 127). */
    private const INBOX_LIMIT = 20;

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(
        private readonly ActiveContainer $activeContainer,
        private readonly ListFavorites $listFavorites,
        private readonly PendingInvitation $pendingInvitation,
    ) {}

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn (): array => $this->auth($request),
            'activeContainer' => fn (): ?string => $this->activeContainer->forUser($request->user()),
            'favorites' => fn (): array => $this->favorites($request),
            'unreadNotificationCount' => fn (): int => $this->unreadNotificationCount($request)
                + $this->pendingInvitationCount($request),
            // Ingen closure runt OptionalProp: den är redan lat, och en
            // kapslad closure hade fått resolvern att packa upp den i två
            // steg i stället för att filtrera den som den prop den är.
            'notifications' => Inertia::optional(fn (): array => $this->notifications($request)),
            // Den andra optionala proppen, av samma skäl som den första: en
            // rad per väntande inbjudan behövs bara när klockan är öppen, och
            // en vanlig sidladdning rör den aldri — se klassens docblock.
            'pendingInvitations' => Inertia::optional(fn (): array => $this->pendingInvitations($request)),
            'locale' => fn (): string => App::getLocale(),
            'translations' => fn (): array => Lang::get('ui'),
            'flash' => [
                'status' => fn (): ?string => $request->session()->get('status'),
            ],
        ];
    }

    /**
     * Den inloggade användaren och hennes konton, eller tomt för en gäst.
     *
     * ETT villkor högst upp, inte en `?->`-kedja per fält: ett fält som
     * glöms blir en null-krasch i en komponent, och ett som glöms i den
     * andra riktningen blir ett läckage. En gäst får `user: null` och
     * `accounts: []` — samma form som en inloggad får, så en komponent
     * aldrig behöver två avpackningsvägar.
     *
     * `accounts.subscription.plan` laddas i förväg. Utan det kostar varje
     * konto egna frågor och en sida med tre konton blir dyrare än en med
     * ett; med det är frågekostnaden konstant i antalet konton. Planen
     * själv kommer ur PlanResource::forAccount(), som äger sitt eget
     * memoiserade free-uppslag.
     */
    private function auth(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [
                'user' => null,
                'accounts' => [],
            ];
        }

        $user->loadMissing('accounts.subscription.plan');

        return [
            'user' => AuthUserResource::make($user)->resolve($request),
            'accounts' => $user->accounts
                ->map(fn (Account $account): array => AccountResource::make($account)->resolve($request))
                ->all(),
        ];
    }

    /**
     * Användarens favoriter som raddata åt skalet, eller tomt för en gäst —
     * se klassens docblock.
     *
     * Ett tidigt `return []` och inte en tom lista ur actionen: en gäst har
     * ingen att fråga för, och `ListFavorites` tar en `User`. Formen på
     * svaret är densamma som för en inloggad utan favoriter, så skalet aldrig
     * behöver två avpackningsvägar — samma regel som `auth()` ovan.
     *
     * `route(..., false)` ger en relativ adress, samma form skalet skriver
     * för hand i sina egna `<Link href="/dashboard">`.
     *
     * @return list<array{name: string, url: string}>
     */
    private function favorites(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return $this->listFavorites->handle($user)
            ->map(fn (Item $item): array => [
                'name' => $item->name,
                'url' => route('containers.items.show', [$item->container, $item], false),
            ])
            ->values()
            ->all();
    }

    /**
     * Klockans siffra: antalet rader skapade EFTER `notifications_read_at`,
     * eller alla användaren har när kolumnen är NULL — se klassens docblock.
     *
     * **Strikt efter, och det är inte en detalj.** Tidsstämpeln är sekundär,
     * och den som öppnar klockan sätter den till `now()`. Med `>=` hade varje
     * rad skapad i samma sekund som öppningen räknats som oläst, och siffran
     * hade stått kvar på samma tal efter att användaren rensat den.
     *
     * **EN fråga, och ingen fråga alls för en gäst.** Tidsstämpeln ligger på
     * användarraden som `auth()` redan har läst, så uppslaget `(user_id,
     * created_at)` är hela kostnaden. En gäst har ingen att fråga för och får
     * noll — samma form som en inloggad utan olästa, så klockan aldrig
     * behöver två avpackningsvägar (samma regel som `auth()` och `favorites()`).
     */
    private function unreadNotificationCount(Request $request): int
    {
        $user = $request->user();

        if ($user === null) {
            return 0;
        }

        return Notification::query()
            ->where('user_id', $user->getKey())
            ->when(
                $user->notifications_read_at !== null,
                fn (Builder $query): Builder => $query->where('created_at', '>', $user->notifications_read_at),
            )
            ->count();
    }

    /**
     * Klockans lista: användarens tjugo senaste notiser, nyast först — den
     * optional-propp som bara en partiell omladdning hämtar.
     *
     * **Ingen annan användares rad kan komma med**, och det är hela urvalet:
     * klockan är personlig, och `user_id` är nyckeln. Raderna under den —
     * containern, subjectet — är uppslag för LÄNKEN och aldrig ett filter;
     * den som förlorat åtkomsten till ett item får sin rad ändå, för notisen
     * handlar om något som hände HENNE ([[Notiser]] § notification). Sidan
     * raden pekar på svarar 404 eller nekad åtkomst, och det är rätt svar:
     * raden är sann, målet finns inte längre för henne.
     *
     * **Subjectet hämtas per typ, i förväg.** Payloaden bär namn och inga
     * ULID:n (Beslut 5: data, aldrig text), så adressen till ett item måste
     * byggas ur raden själv — och `morphWith` ger hela listan i ett konstant
     * antal frågor i stället för en per rad.
     *
     * @return list<array{ulid: string, type: string, payload: array<string, mixed>, url: string|null, created_at: string}>
     */
    private function notifications(Request $request): array
    {
        $user = $request->user();

        if ($user === null) {
            return [];
        }

        return Notification::query()
            ->where('user_id', $user->getKey())
            ->with([
                'container',
                // Relation och inte MorphTo i signaturen: `with()` tar en
                // closure över vilken relation som helst, och en smalare
                // parametertyp hade varit ett kontravariansk brott mot det
                // kontraktet (phpstan). instanceof säger samma sak i kroppen.
                'subject' => function (Relation $relation): void {
                    if ($relation instanceof MorphTo) {
                        $relation->morphWith([
                            ScheduleOccurrence::class => ['schedule.item'],
                            Loan::class => ['item'],
                        ]);
                    }
                },
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::INBOX_LIMIT)
            ->get()
            ->map(fn (Notification $notification): array => [
                'ulid' => $notification->ulid,
                'type' => $notification->type,
                'payload' => $notification->payload,
                'url' => $this->notificationUrl($notification),
                'created_at' => $notification->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Klockans inbjudningsrader: en per väntande inbjudan till användarens
     * VERIFIERADE adress, se issue 131 og [[M20 Kontot]] § 131.
     *
     * **Ur `invitation` och inte ur `notification`.** Ingen notisrad skrivs
     * för en inbjudan — `Notification::TYPE_INVITATION_RECEIVED` finns som
     * konstant men har ingen skrivare — och frågan går därför mot
     * inbjudningstabellen, genom SAMMA uppslag som listan på `/invitations`
     * (App\Support\Invitation\PendingInvitation::forUser()). Två
     * formuleringar av "vilka inbjudningar väntar för den här användaren"
     * hade glidit isär, och klockan hade kunnat visa en rad som sidan inte
     * visar.
     *
     * **Adressen byggs här och inte i JavaScript** (issue 51 § Beslut 7):
     * `/invitations` är statisk, men samma regel gäller varje adress som
     * lämnar servern, och den som en dag får en ULID i sig ska inte behöva
     * flyttas.
     *
     * En overifierad användare får en tom lista — samma svar som en inloggad
     * utan väntande, så klockan aldrig behöver två avpackningsvägar (samma
     * regel som `auth()`, `favorites()` och `notifications()`).
     *
     * @return list<array{ulid: string, container: string, inviter: string, url: string}>
     */
    private function pendingInvitations(Request $request): array
    {
        $user = $this->verifiedUser($request);

        if ($user === null) {
            return [];
        }

        return $this->pendingInvitation->forUser($user)
            ->with(['container', 'invitedBy'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Invitation $invitation): array => [
                'ulid' => $invitation->ulid,
                'container' => $invitation->container->name,
                'inviter' => $invitation->invitedBy->name,
                'url' => route('invitations.show', [], false),
            ])
            ->values()
            ->all();
    }

    /**
     * Siffrans inbjudningsdel: antalet väntande inbjudningar, eller noll för
     * en gäst och för en overifierad adress.
     *
     * `count()` och inte listans längd: siffran ritas på varje sida och
     * behöver inga rader, och en `COUNT(*)` mot indexet `(email, status)` är
     * vad den frågan är till för.
     */
    private function pendingInvitationCount(Request $request): int
    {
        $user = $this->verifiedUser($request);

        if ($user === null) {
            return 0;
        }

        return $this->pendingInvitation->forUser($user)->count();
    }

    /**
     * Användaren om hon är inloggad OCH har verifierat sin adress, annars
     * `null`.
     *
     * Verifieringen är hela identitetsbeviset när ingen token finns
     * ([[ADR-0003 Åtkomstmodell]]): klockan visar en inbjudan till en adress
     * bara när adressen är bevisat hennes. Villkoret står här och inte i
     * PendingInvitation::forUser(), för frågan är adressjämförelsen och
     * ingenting mer — samma uppdelning som
     * App\Http\Controllers\InvitationResponseController::waiting() gör.
     */
    private function verifiedUser(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User && $user->hasVerifiedEmail() ? $user : null;
    }

    /**
     * Adressen en rad pekar på, eller `null` när det inte finns någon sida.
     *
     * **Bara de tre typer som har ett mål länkar.** En kvotvarning och en
     * inaktivitetsvarning gäller KONTOT, och kontosidan finns inte i M19 — en
     * rad utan mål ritas därför som text (issue 127 § Beslut). Adressen
     * byggs här och inte i JavaScript: den bär två ULID:n som bara servern
     * känner (issue 51 § Beslut 7).
     */
    private function notificationUrl(Notification $notification): ?string
    {
        return match ($notification->type) {
            Notification::TYPE_TASK_DUE, Notification::TYPE_TASK_OVERDUE => $this->taskUrl($notification),
            Notification::TYPE_LOAN_DUE => $this->loanUrl($notification),
            Notification::TYPE_TRANSFER_REQUESTED => route('transfers.index', [], false),
            default => null,
        };
    }

    /**
     * Uppgiften till sitt item: förekomsten är radens subject, och vägen går
     * genom schemat.
     */
    private function taskUrl(Notification $notification): ?string
    {
        $occurrence = $notification->subject;

        return $occurrence instanceof ScheduleOccurrence
            ? $this->itemUrl($occurrence->schedule?->item, $notification->container)
            : null;
    }

    /**
     * Lånet till sitt item: lånet är radens subject (issue 127 § Beslut).
     */
    private function loanUrl(Notification $notification): ?string
    {
        $loan = $notification->subject;

        return $loan instanceof Loan
            ? $this->itemUrl($loan->item, $notification->container)
            : null;
    }

    /**
     * En itemväg, eller `null` när målet inte längre finns.
     *
     * Ett mjukraderat item — eller en container i papperskorgen — faller bort
     * genom SoftDeletes' globala scope och ger `null`: raden blir en text i
     * stället för en länk till en 404. Samma svar som händelseloggen ger en
     * gallrad rad, och av samma skäl.
     */
    private function itemUrl(?Item $item, ?Container $container): ?string
    {
        if ($item === null || $container === null) {
            return null;
        }

        return route('containers.items.show', [$container, $item], false);
    }
}

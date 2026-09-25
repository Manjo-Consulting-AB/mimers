<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * En person, se [[Konton och åtkomst]] § user. Tillhör ett eller flera
 * konton via `account_user` (många-till-många). `name` tillagt i issue 3b
 * (#51) — obligatoriskt, precis som `email`. Inget soft delete —
 * dokumentet har inget.
 *
 * `locale`, `timezone` och `unit_system` åsidosätter kontots värden för den
 * här personen när de är satta.
 *
 * `MustVerifyEmail` tillagt i issue 4 — e-postverifiering krävs innan en
 * användare kan ta emot delning, se [[ADR-0011 Autentisering]] §
 * Konsekvenser. `HasApiTokens` (Sanctum) ger personal access tokens för
 * B2B och mobilappar, se [[ADR-0011 Autentisering]].
 *
 * `HasLocalePreference` tillagt i issue 5 (uppföljning efter granskning av
 * PR #34) — se [[ADR-0013 Språk och i18n]] § Konsekvenser: serverrenderat
 * innehåll (mejl, ICS, PDF) väljer språk från mottagarens `locale`, inte
 * requestens, och användarens `locale` åsidosätter kontots. Laravel
 * plockar upp kontraktet självt vid rendering av notifikationer — se
 * `preferredLocale()`.
 *
 * `notifications_read_at` kom med issue 127. Det är klockans "oläst": en
 * TIDSSTÄMPEL på personen och ingen kolumn per notisrad, se
 * [[M19 Dashboarden]] § 127 och migrationen. Är den NULL räknas varje rad
 * användaren har som oläst. Den sätts av
 * App\Http\Controllers\NotificationInboxController och läses av
 * App\Http\Middleware\HandleInertiaRequests — ingen annan rör den, och den
 * ligger därför utanför `#[Fillable]` som resten av tidsstämplarna.
 *
 * `totp_secret` castas `encrypted` sedan issue #19 (TOTP-hemlighet:
 * aktivering och verifiering) — se [[Konton och åtkomst]] § user:
 * "Krypterad", och App\Support\Auth\TotpBroker § Beslut 1, som sätter och
 * läser kolumnen men inte vet något om krypteringen själv. Redan dold i
 * serialisering sedan issue 3 (`#[Hidden]` nedan) — det ändras inte här.
 */
#[Fillable(['name', 'email', 'password_hash', 'locale', 'timezone', 'unit_system', 'quiet_hours_start', 'quiet_hours_end'])]
#[Hidden(['password_hash', 'totp_secret'])]
#[RouteKey('ulid')]
class User extends Authenticatable implements HasLocalePreference, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlid, MustVerifyEmailTrait, Notifiable;

    /**
     * Tabellen heter `user`, inte Eloquents standardplural `users` — den
     * senare droppas i samma issue, se drop_users_table-migrationen.
     */
    protected $table = 'user';

    /**
     * Tabellen `user` har ingen `remember_token`-kolumn och datamodellen
     * beskriver ingen — se issue #17 § Beslut som redan är fattade:
     * "Ingen 'kom ihåg mig'." En tom sträng gör att Laravel hoppar över
     * remember-token helt i stället för att krascha mot en kolumn som inte
     * finns. Ihållande inloggning över lång tid är ett produktbeslut som
     * hör hemma i en ADR, inte här.
     */
    protected $rememberTokenName = '';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'last_active_at' => 'datetime',
            'notifications_read_at' => 'datetime',
            'password_hash' => 'hashed',
        ];
    }

    /**
     * Lösenordskolumnen heter `password_hash`, inte Laravels standard
     * `password`. Får vara NULL — en användare kan logga in enbart via
     * magic link, se issue 5.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    /**
     * Konton den här personen är medlem i. Rollen (`owner` | `admin` |
     * `member`) lagras på kopplingstabellen `account_user`, se
     * [[Konton och åtkomst]] § account_user.
     *
     * @return BelongsToMany<Account, $this>
     */
    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'account_user')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Personens favoritmarkeringar — en rad per item hon märkt, se
     * [[ADR-0042 Designsystemet]] § Konsekvenser och [[M17 Designsystemet]]
     * § 105.
     *
     * **Per användare och inte per item**, och därför en egen relation och
     * ingen kolumn på `item`: markeringen är personens bokmärke, och två
     * personer som ser samma item ska kunna märka det oberoende av varandra.
     *
     * Relationen bär ingen åtkomst. Den säger vad användaren har märkt, inte
     * vad hon får se — App\Http\Controllers\FavoriteController prövar
     * App\Policies\ItemPolicy::view() innan något skrivs hit, och issue 106
     * filtrerar listan genom App\Actions\Access\ResolveItemScope som allt
     * annat. Ett item hon förlorat åtkomsten till försvinner därför ur
     * listan medan raden ligger kvar.
     *
     * @return HasMany<Favorite, $this>
     */
    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    /**
     * Begärda adressändringar — en rad per begäran, se [[M20 Kontot]] § 130
     * och [[Konton och åtkomst]] § email_change.
     *
     * **Raden hänger på personen och inte på adressen**, till skillnad från
     * `magic_link_token`: den som bekräftar bytet måste vara samma användare
     * som begärde det, och App\Actions\Account\ConfirmEmailChange jämför
     * `user_id` innan något skrivs. En obekräftad rad är en pågående begäran;
     * en bekräftad rad är historik över ett byte som redan skett.
     *
     * @return HasMany<EmailChange, $this>
     */
    public function emailChanges(): HasMany
    {
        return $this->hasMany(EmailChange::class);
    }

    /**
     * Tipsen personen kryssat bort i informationsytan — en rad per nyckel,
     * se [[M19 Dashboarden]] § 128 och App\Support\Tips.
     *
     * **Per användare och per nyckel, och därför en tabell och ingen flagga.**
     * Det dolda tillståndet följer personen mellan webbläsare (issuens krav 1)
     * och gäller ett tips i taget (krav 2): ett tips som läggs till senare har
     * ingen rad här och visas därför även för den som dolt allt som fanns
     * förut. Relationen bär ingen åtkomst och ingen regel — vilka nycklar som
     * finns står i App\Support\Tips, och
     * App\Http\Controllers\DismissedTipController prövar den innan något
     * skrivs hit.
     *
     * @return HasMany<DismissedTip, $this>
     */
    public function dismissedTips(): HasMany
    {
        return $this->hasMany(DismissedTip::class);
    }

    /**
     * Se [[ADR-0013 Språk och i18n]] § Konsekvenser: "användarens locale
     * åsidosätter kontots." Är `locale` satt på användaren används den
     * rakt av.
     *
     * Är den NULL faller vi tillbaka på kontots — men bara när personen är
     * medlem i exakt ETT konto. Fler än ett konto (en B2B-personal som
     * hör till flera organisationer) är ett gränsfall dokumentationen inte
     * tar upp, se PR #34-uppföljningen "Frågor och antaganden": det finns
     * inget entydigt "kontot" att falla tillbaka på då, och att gissa ett
     * (t.ex. först skapade, eller det med rollen `owner`) vore att hitta på
     * en regel ingen bett om. Returnerar i stället NULL, vilket Laravels
     * `Illuminate\Support\Traits\Localizable::withLocale()` tolkar som "rör
     * inte den aktiva locale-inställningen" — appens vanliga default
     * gäller (`config('app.locale')`), i stället för en gissning.
     */
    public function preferredLocale(): ?string
    {
        if ($this->locale !== null) {
            return $this->locale;
        }

        $accounts = $this->accounts;

        return $accounts->count() === 1 ? $accounts->first()->locale : null;
    }

    /**
     * Personens tidszon, med kontots som reserv och appens som sista utväg
     * ([[Konton och åtkomst]] § user: `timezone` åsidosätter kontots värden
     * för den här personen).
     *
     * **Regeln bor här och inte i anroparen** (issue 135). Den fanns tidigare
     * som två privata kopior — App\Http\Controllers\DashboardController::
     * timezoneFor() och App\Support\Notification\QuietHours::timezoneFor() —
     * och två kopior av samma regel är förr eller senare två svar på samma
     * fråga. QuietHours har sin kvar än så länge; den rörs inte av issue 135.
     *
     * `first()` är godtyckligt när användaren har flera konton — accepterat,
     * en gissning är bättre än UTC. Samma resonemang som `preferredLocale()`
     * och QuietHours::timezoneFor(); `account.timezone` är till skillnad från
     * `user.timezone` inte nullable, så reserven finns alltid.
     */
    public function preferredTimezone(): string
    {
        $timezone = $this->timezone;

        if ($timezone === null && $this->accounts->isNotEmpty()) {
            $timezone = $this->accounts->first()->timezone;
        }

        return $timezone ?? config('app.timezone');
    }

    /**
     * Personens kalenderdatum — vad "idag" betyder för henne (issue 135).
     *
     * **Datumet är midnatt i APPENS tidszon, inte i hennes.** `due_at` och
     * `visible_from` är DATE-kolumner som lagras och jämförs i UTC, och
     * `Carbon::today('Europe/Stockholm')` är ett annat ÖGONBLICK än midnatt i
     * UTC — 22:00 dagen innan. Jämförs det med en DATE-kolumn blir svaret fel,
     * både med `lessThan()` och rakt in i `whereDate()`. Tidszonen används
     * därför bara för att avgöra VILKET datum hon är i; själva datumet byggs
     * om till appens tidszon. Frågorna läser `toDateString()`.
     */
    public function today(): Carbon
    {
        return Carbon::parse(Carbon::now($this->preferredTimezone())->toDateString());
    }
}

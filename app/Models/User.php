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
}

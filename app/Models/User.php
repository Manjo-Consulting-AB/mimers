<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * En person, se [[Konton och åtkomst]] § user. Tillhör ett eller flera
 * konton via `account_user` (många-till-många). Ingen `name`-kolumn och
 * inget soft delete — dokumentet har varken.
 *
 * `locale`, `timezone` och `unit_system` åsidosätter kontots värden för den
 * här personen när de är satta.
 *
 * `MustVerifyEmail` tillagt i issue 4 — e-postverifiering krävs innan en
 * användare kan ta emot delning, se [[ADR-0011 Autentisering]] §
 * Konsekvenser. `HasApiTokens` (Sanctum) ger personal access tokens för
 * B2B och mobilappar, se [[ADR-0011 Autentisering]].
 */
#[Fillable(['email', 'password_hash', 'locale', 'timezone', 'unit_system', 'quiet_hours_start', 'quiet_hours_end'])]
#[Hidden(['password_hash', 'totp_secret'])]
#[RouteKey('ulid')]
class User extends Authenticatable implements MustVerifyEmail
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
}

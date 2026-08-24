<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * En person, se [[Konton och åtkomst]] § user. Tillhör ett eller flera
 * konton via `account_user` (många-till-många). Ingen `name`-kolumn och
 * inget soft delete — dokumentet har varken.
 *
 * `locale`, `timezone` och `unit_system` åsidosätter kontots värden för den
 * här personen när de är satta.
 */
#[Fillable(['email', 'password_hash', 'locale', 'timezone', 'unit_system', 'quiet_hours_start', 'quiet_hours_end'])]
#[Hidden(['password_hash', 'totp_secret'])]
#[RouteKey('ulid')]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUlid, Notifiable;

    /**
     * Tabellen heter `user`, inte Eloquents standardplural `users` — den
     * senare droppas i samma issue, se drop_users_table-migrationen.
     */
    protected $table = 'user';

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

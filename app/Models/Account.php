<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Ägarenheten i systemet, se [[Konton och åtkomst]] § account och
 * [[ADR-0002 Konto äger container]]. Ett privatkonto är bara ett konto med
 * en enda medlem — samma tabell som en organisation med flera.
 *
 * Inget soft delete: kontolivscykeln går via `status`
 * (`active` | `read_only` | `closed`), inte via `deleted_at`.
 */
#[Fillable(['type', 'name', 'locale', 'timezone', 'unit_system', 'status', 'read_only_reason'])]
#[RouteKey('ulid')]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `account`, inte Eloquents standardplural `accounts`.
     */
    protected $table = 'account';

    /**
     * Kontots medlemmar. Rollen (`owner` | `admin` | `member`) lagras på
     * kopplingstabellen `account_user`, se [[Konton och åtkomst]]
     * § account_user.
     *
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'account_user')
            ->withPivot('role');
    }
}

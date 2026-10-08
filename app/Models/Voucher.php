<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * En inbjudningskod — den släpper in en ny person i betan och ger henne den
 * plan den bär. Se [[ADR-0055 Inbjudningskoder och stängd registrering]] § 4
 * och [[Konton och åtkomst]] § voucher.
 *
 * `code_hash` är alltid en SHA-256-hex av den NORMALISERADE koden, aldrig
 * koden själv — samma grepp som App\Models\Invitation::$token_hash. Koden
 * visas en enda gång, när den skapas (App\Actions\Voucher\GenerateVoucher),
 * och går därefter bara att slå upp, inte att läsa ut. `#[Hidden]` är
 * skyddsnätet utifall raden någonsin serialiseras direkt.
 *
 * `code_hash`, `plan_id`, `used_count` och `revoked_at` är medvetet
 * UTESLUTNA ur `#[Fillable]`, samma resonemang som
 * App\Models\Subscription::$plan_id och $external_ref: hashen sätts av
 * GenerateVoucher, planen binds vid skapandet, `used_count` räknas upp inne
 * i inlösens transaktion och `revoked_at` sätts av `voucher:revoke`. Inget
 * av dem får sättas via massildelning från en request — och ingen request
 * skapar en voucher alls; ytan är Artisan (ADR-0055 § 7).
 *
 * Ingen `HasFactory`: ingen fabrik finns i den här issuen, och provraden
 * byggs i testfilerna. Ingen `deleted_at`: `revoked_at` är livscykeln.
 */
#[Fillable(['label', 'duration_days', 'max_uses', 'grants_registration', 'expires_at'])]
#[Hidden(['code_hash'])]
#[RouteKey('ulid')]
class Voucher extends Model
{
    use HasUlid;

    /**
     * Tabellen heter `voucher`, i singular liksom `plan` och `subscription`.
     */
    protected $table = 'voucher';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'duration_days' => 'integer',
            'max_uses' => 'integer',
            'used_count' => 'integer',
            'grants_registration' => 'boolean',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * Planen koden ger vid inlösen. Obligatorisk (FK, RESTRICT).
     *
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Kodens inlösen. Raden raderas aldrig, men ett raderat konto lämnar
     * `account_id = NULL` (SET NULL), så listan kan innehålla rader utan
     * konto — se migrationen för `voucher_redemption`.
     *
     * @return HasMany<VoucherRedemption, $this>
     */
    public function redemptions(): HasMany
    {
        return $this->hasMany(VoucherRedemption::class);
    }

    /**
     * Normalisera en kod före hashning och uppslag (ADR-0055 § 5): versaler,
     * utan bindestreck och mellanslag, och `O`, `I` och `L` läses som `0`,
     * `1` och `1`.
     *
     * De tre bokstäverna är de Crockfords base32 utesluter just för att de
     * förväxlas med siffror när koden skrivs av för hand. Att läsa dem som
     * sina siffror gör en felhörning till en träff i stället för till en
     * nekad registrering.
     */
    public static function normalize(string $code): string
    {
        $utan_avskiljare = str_replace(['-', ' '], '', Str::upper($code));

        return str_replace(['O', 'I', 'L'], ['0', '1', '1'], $utan_avskiljare);
    }

    /**
     * Slå upp en voucher på klartextkoden. Den ENDA vägen från en kod till
     * en rad: hashen beräknas här, så en anropare kan aldrig råka jämföra
     * okodad text mot `code_hash`.
     */
    public static function findByCode(string $code): ?self
    {
        return static::query()
            ->where('code_hash', hash('sha256', static::normalize($code)))
            ->first();
    }

    /**
     * Går koden att lösa in? Formulerat på ett ställe (ADR-0055 § 6): inte
     * återkallad, inte utgången och uttag kvar.
     *
     * Utgången härleds ur `expires_at` och aldrig ur en kolumn som flippas —
     * ingen bakgrundsprocess rör raden, samma princip som
     * App\Models\Invitation::scopeOutstanding().
     *
     * Att koden är inlösbar säger ingenting om den släpper in en
     * registrering; det är `grants_registration`, och den prövas av
     * App\Actions\Auth\AdmitRegistration.
     */
    public function isRedeemable(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return $this->used_count < $this->max_uses;
    }
}

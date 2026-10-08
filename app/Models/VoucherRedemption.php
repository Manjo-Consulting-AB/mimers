<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En inlösen: koden användes av det här kontot och den här personen — se
 * [[ADR-0055 Inbjudningskoder och stängd registrering]] § 4 och [[Konton och
 * åtkomst]] § voucher.
 *
 * Raden skrivs av App\Actions\Voucher\RedeemVoucher inne i samma transaktion
 * som `voucher.used_count` räknas upp. Den är historik, inte åtkomst: att
 * raden finns ger ingenting i sig, den berättar hur någon kom in.
 *
 * `account_id` och `user_id` är nullbara med `SET NULL` (ADR-0055 § 4): en
 * raderad person eller ett raderat konto ska inte hindras av en inlösen, och
 * raden står kvar som bevis för att koden förbrukats.
 *
 * Ingen `ulid` och ingen `deleted_at`: raden identifieras aldrig i en rutt
 * och är inget innehåll — samma form som App\Models\AttachmentOpen.
 *
 * `#[Fillable([])]`: alla tre nycklarna sätts explicit av RedeemVoucher ur
 * vouchern, kontot och användaren, aldrig via massildelning (samma regel som
 * App\Models\RecentVisit).
 */
#[Fillable([])]
class VoucherRedemption extends Model
{
    /**
     * Tabellen heter `voucher_redemption`, inte Eloquents standardplural.
     */
    protected $table = 'voucher_redemption';

    /**
     * Vouchern som löstes in. Obligatorisk (FK, RESTRICT) — raden försvinner
     * med koden, och koden raderas aldrig.
     *
     * @return BelongsTo<Voucher, $this>
     */
    public function voucher(): BelongsTo
    {
        return $this->belongsTo(Voucher::class);
    }

    /**
     * Kontot inlösen gav planen. `NULL` när kontot raderats.
     *
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Personen som löste in koden. `NULL` när hon raderats.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

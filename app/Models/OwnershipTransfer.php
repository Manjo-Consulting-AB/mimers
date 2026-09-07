<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\OwnershipTransferFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ett ägarbyte — överlåtelsen av en hel pärm, se [[Konton och åtkomst]] §
 * ownership_transfer och [[ADR-0003 Åtkomstmodell]]. Täcker
 * nybyggnadsvarv → kund, mäklare → köpare och privat försäljning med samma
 * mekanism. Raden ligger `pending` tills den accepteras (39b), avvisas, dras
 * tillbaka eller löper ut, och raderas aldrig: `revoked` är avsändarens ånger
 * och historiken är underlaget för M9.
 *
 * Tre kolumner är värda en kommentar så att nästa läsare inte tror att de är
 * slarv (issue 39a § Beslut 2):
 *
 * - `to_account_id` är nullable. Exakt en av `to_account_id` och `to_email`
 *   är satt, se migrationens CHECK-villkor.
 * - `initiated_by_user_id` finns, som systertabellens `invited_by_user_id` —
 *   39b sätter den som `granted_by_user_id` på den kvarhållna åtkomsten.
 * - `status` tillåter `revoked`, avsändarens ånger, av samma skäl som på
 *   `invitation`: en överlåtelse skickad till fel adress måste gå att dra
 *   tillbaka.
 *
 * `excluded_item_ids` bär item-ULID:er, inte löpnummer (Beslut 3): en
 * JSON-lista kan aldrig bära en främmande nyckel, så det enda som skyddar den
 * är valideringen i StoreOwnershipTransferRequest. Tom lista serialiseras som
 * `[]`, aldrig `null`.
 *
 * Allt sätts explicit av App\Http\Controllers\Api\OwnershipTransferController,
 * aldrig via massildelning — `#[Fillable]` är därför tom, samma resonemang
 * som App\Models\Notification.
 */
#[Fillable([])]
#[RouteKey('ulid')]
class OwnershipTransfer extends Model
{
    /** @use HasFactory<OwnershipTransferFactory> */
    use HasFactory, HasUlid;

    /**
     * De giltiga värdena för `status`, se migrationens CHECK-villkor och
     * issue 39a § Beslut 2. Delas med Database\Factories\OwnershipTransferFactory
     * så listan bara underhålls på ett ställe.
     *
     * `expired` finns i uppsättningen för fullständighetens skull men skrivs
     * aldrig av den här issuen — utgång härleds i kod, se isExpired() nedan
     * och issue 39a § Beslut 10. Samma princip som `invitation` och
     * `container_access`.
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'accepted', 'rejected', 'expired', 'revoked'];

    /**
     * Hur länge ett ägarbyte är giltigt, se issue 39a § Beslut 10.
     * Dokumentet anger ingen siffra; trettio dagar är valt för att
     * mottagaren ska hinna reagera på en påminnelse utan att en överlåtelse
     * till fel adress ligger kvar som ett öppet sår i onödan.
     */
    public const TTL_DAYS = 30;

    /**
     * Tabellen heter `ownership_transfer`, i singular med understreck.
     */
    protected $table = 'ownership_transfer';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'excluded_item_ids' => 'array',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Containern som ska byta ägare.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Kontot som ger bort containern — ägarkontot vid initieringen.
     *
     * @return BelongsTo<Account, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * Kontot som tar emot, när mottagaren har ett konto. Null när
     * mottagaren nås på `to_email` — exakt en av vägarna är satt.
     *
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    /**
     * Den användare som initierade överlåtelsen. Obligatorisk (FK, RESTRICT).
     *
     * @return BelongsTo<User, $this>
     */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /**
     * Har ägarbytet passerat sin levnadstid? SANNINGEN om utgång, se issue
     * 39a § Beslut 10: kolumnen `status` står kvar på `pending` — ingen
     * bakgrundsprocess flippar den — och utgången härleds ur `created_at` +
     * TTL_DAYS. Ett utgånget ägarbyte listas inte som inkommande och kan
     * inte besvaras, men ägaren ser det i sin lista redovisat som `expired`
     * av App\Http\Resources\OwnershipTransferResource.
     */
    public function isExpired(): bool
    {
        return $this->created_at->addDays(self::TTL_DAYS)->isPast();
    }
}

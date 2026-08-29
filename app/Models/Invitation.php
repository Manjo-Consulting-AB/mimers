<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * En inbjudan att dela en container med någon som ännu inte har konto — se
 * [[Konton och åtkomst]] § invitation och [[ADR-0003 Åtkomstmodell]].
 * Raden ligger `pending` tills den accepteras, avvisas, dras tillbaka
 * eller löper ut, och raderas aldrig: `revoked` är avsändarens ånger och
 * historiken är underlaget för M9.
 *
 * `token_hash` är alltid en SHA-256-hex av den slump som skickas i mejlets
 * länk, aldrig slumpen själv — se issue 10a § Beslut 5 och förlagan
 * App\Support\Auth\MagicLinkBroker. `#[Hidden]` är ett skyddsnät utifall
 * raden någonsin serialiseras direkt; App\Http\Resources\InvitationResource
 * listar ändå bara de fält den ska.
 *
 * `container_id`, `token_hash`, `status` och `invited_by_user_id` är
 * medvetet UTESLUTNA ur `#[Fillable]`, samma resonemang som
 * `Container::$account_id` och `ContainerAccess::$granted_by_user_id` — de
 * sätts explicit av App\Http\Controllers\Api\ContainerInvitationController,
 * aldrig via massildelning (issue 10a § Beslut 15). Kvar som `#[Fillable]`
 * blir `email` och `level`, precis de två fält klienten skickar.
 *
 * @property Carbon $expires_at
 */
#[Fillable(['email', 'level'])]
#[Hidden(['token_hash'])]
#[RouteKey('ulid')]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory, HasUlid;

    /**
     * De giltiga värdena för `status`, se migrationens CHECK-villkor och
     * issue 10a § Beslut 3. Delas med Database\Factories\InvitationFactory
     * så listan bara underhålls på ett ställe, samma mönster som
     * `Container::KINDS`.
     *
     * `expired` finns i uppsättningen för fullständighetens skull men
     * skrivs aldrig av den här issuen — utgång härleds i kod, se
     * isExpired() nedan och issue 10a § Beslut 7.
     *
     * @var list<string>
     */
    public const STATUSES = ['pending', 'accepted', 'rejected', 'expired', 'revoked'];

    /**
     * Hur länge en inbjudan är giltig, se issue 10a § Beslut 8.
     * Dokumentationen anger ingen siffra; fjorton dagar är valt för att
     * mottagaren inte bara ska klicka en länk utan hinna SKAPA ETT KONTO
     * och verifiera sin e-post innan hon kan acceptera
     * ([[ADR-0003 Åtkomstmodell]] § Beslut) — ett magic link-fönster på
     * minuter (App\Support\Auth\MagicLinkBroker::TTL_MINUTES) vore fel
     * storleksordning, och en inbjudan som överlever en semester blir i
     * stället en utestående behörighet ingen minns. Avvikande TTL per
     * inbjudan tas inte emot från klienten.
     */
    public const TTL_DAYS = 14;

    /**
     * Tabellen heter `invitation`, i singular liksom `account`, `user` och
     * `container` — se AGENTS.md § Databaskonventioner.
     */
    protected $table = 'invitation';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Containern inbjudan gäller.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Den som bjöd in. Obligatorisk (FK, RESTRICT).
     *
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }

    /**
     * Har inbjudan passerat sin `expires_at`? SANNINGEN om utgång, se
     * issue 10a § Beslut 7 och § Att se upp med ("härled inte `expired` på
     * två ställen"): kolumnen `status` står kvar på `pending` — ingen
     * bakgrundsprocess flippar den — och det är
     * App\Http\Resources\InvitationResource som redovisar `expired` utåt
     * genom att anropa den här metoden. 10b delar samma metod vid accept.
     *
     * Samma form som App\Models\MagicLinkToken::isExpired().
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

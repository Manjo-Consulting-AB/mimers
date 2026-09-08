<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Database\Factories\ExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En beställd export av en container — metadata och filer i en ZIP, se
 * [[Backlog]] M6 § 41. Raden skapas som `pending` av
 * App\Http\Controllers\Api\ExportController när någon begär en export;
 * App\Jobs\BuildContainerExport bygger artefakten och för raden genom
 * `running` → `ready` (eller `failed`). Inget annat skriver status.
 *
 * `status` är en sluten mängd — värdena ligger i migrationens CHECK-villkor
 * och som konstanter här, så varken kontrollern eller jobbet någonsin stavar
 * strängarna. `expired` sätts av gallringen i 41b, inte av den här issuen.
 *
 * `ulid` är identifieraren utåt (klienten pollar raden genom den), och både
 * container-ULID:en och export-ULID:en bygger sökvägen på disken (Beslut 6)
 * — sökvägen konstrueras alltid av koden, aldrig av indata.
 *
 * Inget `deleted_at` och inga statusrader som rensas: raden är historik över
 * en beställning, precis som WebhookDelivery.
 */
#[Fillable([])]
#[RouteKey('ulid')]
class Export extends Model
{
    /** @use HasFactory<ExportFactory> */
    use HasFactory, HasUlid;

    /**
     * Tabellen heter `export`, inte Eloquents standardplural `exports`.
     */
    protected $table = 'export';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    /**
     * De giltiga statusvärdena, se migrationens CHECK-villkor.
     *
     * @var list<string>
     */
    public const STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING, self::STATUS_READY, self::STATUS_FAILED, self::STATUS_EXPIRED];

    /**
     * Get the attributes that should be cast.
     *
     * `byte_size` är BIGINT UNSIGNED och `expires_at` en tidsstämpel — i
     * sqlite (testsviten) ligger heltal och tidsstämplar som text, och utan
     * casten vore jämförelserna fel, se WebhookDelivery::casts().
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * Containern som exporteras — exakt en. Nyckeln är ON DELETE RESTRICT,
     * så containern finns kvar så länge raden finns.
     *
     * @return BelongsTo<Container, $this>
     */
    public function container(): BelongsTo
    {
        return $this->belongsTo(Container::class);
    }

    /**
     * Användaren som beställde exporten — exakt en. Vyns språk (index.html)
     * väljs ur hens locale (Beslut 11).
     *
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}

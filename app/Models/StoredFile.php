<?php

namespace App\Models;

use Database\Factories\StoredFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * De lagrade bytena, innehållsadresserade — se [[Filer och lagring]] §
 * stored_file och [[ADR-0006 Innehållsadresserad lagring]]. En rad per
 * unikt innehåll i hela systemet; `content_hash` är SHA-256-hex som alltid
 * beräknas på servern (issue 16a § Beslut 3).
 *
 * Ingen `ulid` och ingen `#[RouteKey]` — tabellen syns aldrig i API:et
 * (issue 16a § Beslut 12 och 13). Ingen SoftDeletes — en stored_file lever
 * exakt så länge någon refererar den; minskningen av `reference_count` och
 * den fysiska raderingen är issue 17.
 *
 * Alla kolumner är `#[Fillable]`: inget på den här tabellen sätts från en
 * klientbegäran (tabellen har ingen API-yta), alla värden kommer från
 * App\Actions\Attachment\StoreAttachment. `purge_after` sätts av
 * App\Actions\Attachment\PurgeAttachment när räknaren når noll (issue 17a).
 */
#[Fillable(['content_hash', 'byte_size', 'mime_type', 'storage_path', 'reference_count', 'scan_status', 'purge_after'])]
class StoredFile extends Model
{
    /** @use HasFactory<StoredFileFactory> */
    use HasFactory;

    /**
     * Tabellen heter `stored_file`, inte Eloquents standardplural.
     */
    protected $table = 'stored_file';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'reference_count' => 'integer',
            'purge_after' => 'datetime',
        ];
    }

    /**
     * De attachments som refererar de här bytena. Relationen behövs av
     * `reference_count`-resonemanget (issue 17) och för baklängesuppslag.
     *
     * @return HasMany<Attachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }
}

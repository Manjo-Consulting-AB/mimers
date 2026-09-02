<?php

namespace App\Models;

use Database\Factories\ImageDerivativeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * En genererad miniatyr av en stored_file — se [[Filer och lagring]] §
 * image_derivative. Raderna skapas av App\Jobs\GenerateImageDerivatives vid
 * uppladdning och raderas av App\Console\PurgesExpiredStoredFiles i samma
 * transaktion som bytena (issue 18 § Beslut 7).
 *
 * Ingen `ulid` och ingen `#[RouteKey]` — derivatet syns aldrig som egen
 * resurs, det nås via bilagan (issue 18 § Beslut 1). Ingen SoftDeletes — ett
 * derivat lever exakt så länge dess `stored_file` gör.
 *
 * `byte_size` räknas aldrig mot användarens kvot (§ Beslut 6) — den finns
 * för kapacitetsplanering. Alla kolumner är `#[Fillable]`: inget på den här
 * tabellen sätts från en klientbegäran, alla värden kommer från
 * miniatyrjobbet.
 */
#[Fillable(['stored_file_id', 'variant', 'storage_path', 'byte_size'])]
class ImageDerivative extends Model
{
    /** @use HasFactory<ImageDerivativeFactory> */
    use HasFactory;

    /**
     * Tabellen heter `image_derivative`, inte Eloquents standardplural.
     */
    protected $table = 'image_derivative';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
        ];
    }

    /**
     * Originalet derivatet är en miniatyr av.
     *
     * @return BelongsTo<StoredFile, $this>
     */
    public function storedFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class);
    }
}

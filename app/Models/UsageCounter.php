<?php

namespace App\Models;

use Database\Factories\UsageCounterFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Förbrukning per konto — se [[Planer och kvoter]] § usage_counter. En rad
 * per konto (`account_id` är unikt), uppdaterad transaktionellt av
 * App\Actions\Usage\AdjustUsage när en bilaga eller container blir levande
 * eller slutar vara levande (issue 26a).
 *
 * Räknaren är en cache av två frågor, och de två sanningarna formuleras på
 * ETT ställe — i metoderna calculateStorageBytes() och
 * calculateContainerCount() nedan — så att 26b:s avstämning använder exakt
 * samma formulering när den räknar om och jämför (issue 26a § Beslut 2).
 *
 * Ingen `ulid` och ingen `#[RouteKey]` — raden syns aldrig i API:et, den
 * läses genom sitt konto (issue 26a § Beslut 1). Ingen SoftDeletes — en
 * räknare är inte användarskapat innehåll.
 */
#[Fillable(['account_id', 'storage_bytes', 'container_count'])]
class UsageCounter extends Model
{
    /** @use HasFactory<UsageCounterFactory> */
    use HasFactory;

    /**
     * Tabellen heter `usage_counter`, inte Eloquents standardplural.
     */
    protected $table = 'usage_counter';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'storage_bytes' => 'integer',
            'container_count' => 'integer',
        ];
    }

    /**
     * Sanningen som `storage_bytes` cachar: summan av bytena för kontots
     * levande bilagor, mätt som LOGISK storlek. Två bilagor på samma
     * stored_file räknas båda — dedupen sänker bara den faktiska
     * diskförbrukningen ([[Filer och lagring]] § Kvot kontra faktisk
     * lagring, issue 26a § Beslut 2).
     */
    public static function calculateStorageBytes(int $accountId): int
    {
        return (int) DB::table('attachment')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->where('attachment.billed_account_id', $accountId)
            ->whereNull('attachment.deleted_at')
            ->sum('stored_file.byte_size');
    }

    /**
     * Sanningen som `container_count` cachar: antalet levande containers som
     * ägs av kontot (issue 26a § Beslut 2).
     */
    public static function calculateContainerCount(int $accountId): int
    {
        return (int) DB::table('container')
            ->where('account_id', $accountId)
            ->whereNull('deleted_at')
            ->count();
    }
}

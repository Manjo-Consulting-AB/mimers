<?php

namespace App\Console;

use App\Actions\Trash\PurgeContent;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Item;
use App\Models\Tag;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Gallrar utgånget innehåll ur papperskorgen — issue 20b. Rader vars
 * `deleted_at` passerat retentionen (30 dagar, se [[ADR-0008 Soft delete och
 * papperskorg]] § Retentionstiden i MVP) tas bort på riktigt; 20a listade och
 * återställde, men lämnade raderna åt det här jobbet.
 *
 * Klassens enda uppgift är att välja VAD som ska gallras och se till att
 * retentionen har passerats (Beslut 1 och 11). HUR varje typ gallras ägs av
 * App\Actions\Trash\PurgeContent (Beslut 2): konsolen kör bara igenom dess
 * fyra metoder, i ordningen bilagor → items → kategorier → taggar (Beslut 7).
 *
 * Villkoret är `deleted_at IS NOT NULL` OCH äldre än retentionen — en levande
 * rad får aldrig röras av det här jobbet (Beslut 11). Undantaget är bilagorna
 * som PurgeContent::item tar med sig oavsett deras eget tillstånd (Beslut 4):
 * ett item som gallras har inget att hänga en bilaga på.
 *
 * En rad i taget, och ett fel stoppar inte de andra (Beslut 8): varje rad
 * ligger i sitt eget `try`, felet loggas med typ och ULID, och körningen går
 * vidare. Samma resonemang som 17b § Beslut 3 — en enda trasig rad får inte
 * lämna hela gallringen ogjord natt efter natt.
 *
 * `handle(): array` returnerar antalet gallrade poster per typ och loggar en
 * rad när summan är över noll; tyst när det inte fanns något att göra
 * (Beslut 10, samma som 17b § Beslut 5).
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Driftmiljön saknar proc_open. Av
 * samma skäl som PrunesExpiredMagicLinkTokens är klassen medvetet fri från
 * Artisan-beroenden: den anropas som en ren closure-kallbar.
 */
class PurgesExpiredTrash
{
    public function __construct(
        private readonly PurgeContent $purgeContent,
    ) {}

    /**
     * Gallrar allt vars retention har passerats.
     *
     * @return array{attachment: int, item: int, category: int, tag: int}
     */
    public function handle(): array
    {
        $cutoff = now()->subDays((int) config('files.trash_retention_days'));

        $borttagna = [
            'attachment' => $this->gallra(
                'attachment',
                Attachment::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Attachment $bilaga) => $this->purgeContent->attachment($bilaga),
            ),
            'item' => $this->gallra(
                'item',
                Item::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Item $item) => $this->purgeContent->item($item),
            ),
            'category' => $this->gallra(
                'category',
                Category::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Category $kategori) => $this->purgeContent->category($kategori),
            ),
            'tag' => $this->gallra(
                'tag',
                Tag::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Tag $tagg) => $this->purgeContent->tag($tagg),
            ),
        ];

        if (array_sum($borttagna) > 0) {
            Log::info('Gallrade utgånget innehåll ur papperskorgen.', $borttagna);
        }

        return $borttagna;
    }

    /**
     * Gallrar en typ rad för rad. `chunkById` och inte `chunk` — raderna
     * försvinner under iterationen och pagingen måste följa primärnyckeln,
     * inte radnumret (Beslut 8).
     *
     * @template TModel of Item|Attachment|Category|Tag
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): void  $perRad
     */
    private function gallra(string $typ, Builder $query, Closure $perRad): int
    {
        $borttagna = 0;

        $query->chunkById(100, function ($rader) use ($typ, $perRad, &$borttagna): void {
            foreach ($rader as $rad) {
                try {
                    $perRad($rad);
                    $borttagna++;
                } catch (Throwable $e) {
                    Log::error('Kunde inte gallra utgånget innehåll ur papperskorgen.', [
                        'type' => $typ,
                        'ulid' => $rad->ulid,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $borttagna;
    }
}

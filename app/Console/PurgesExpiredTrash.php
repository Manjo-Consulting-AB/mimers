<?php

namespace App\Console;

use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\LegalHold;
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
 * Sedan 20c gallrar den också raderade containers vars
 * `deleted_at` passerat retentionen — genom App\Actions\Trash\PurgeContainer,
 * som tar med sig hela innehållet (Beslut 6: en femte gren i samma jobb, ett
 * jobb per natt som gör hela papperskorgen är lättare att resonera om än två).
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
 * `handle(): array` returnerar antalet gallrade poster per typ (containern
 * räknas som en post) och loggar en rad när summan är över noll; tyst när
 * det inte fanns något att göra (Beslut 10, samma som 17b § Beslut 5).
 *
 * Den rättsliga spärren (issue 112) är jobbets sista grind: en rad vars konto
 * är spärrat gallras inte, och hoppas över utan att räknas. Kontrollen ligger
 * här och inte i PurgeContent eller PurgeContainer — de är verktygen, det här
 * jobbet är grinden — och den ställs med LegalHold::covers() för radens
 * ägande konto, en enda fråga formulerad på ett enda ställe. Kontots innehåll
 * skyddas därmed utan att de lagrade filerna behöver en egen kontroll: en
 * bilaga som inte gallras behåller sin referens, och
 * App\Console\PurgesExpiredStoredFiles rör bara filer utan referenser.
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
        private readonly PurgeContainer $purgeContainer,
    ) {}

    /**
     * Gallrar allt vars retention har passerats.
     *
     * @return array{attachment: int, item: int, category: int, tag: int, container: int}
     */
    public function handle(): array
    {
        $cutoff = now()->subDays((int) config('files.trash_retention_days'));

        $deleted = [
            'attachment' => $this->purge(
                'attachment',
                Attachment::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Attachment $attachment) => $this->purgeContent->attachment($attachment),
            ),
            'item' => $this->purge(
                'item',
                Item::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Item $item) => $this->purgeContent->item($item),
            ),
            'category' => $this->purge(
                'category',
                Category::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Category $category) => $this->purgeContent->category($category),
            ),
            'tag' => $this->purge(
                'tag',
                Tag::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Tag $tag) => $this->purgeContent->tag($tag),
            ),
            // 20c · Raderade containers: PurgeContainer tar med sig hela
            // innehållet, och varje container ligger i sin egen transaktion
            // (Beslut 7) — samma chunkById/felhantering/loggning som de fyra
            // grenarna ovan.
            'container' => $this->purge(
                'container',
                Container::onlyTrashed()->where('deleted_at', '<=', $cutoff),
                fn (Container $container) => $this->purgeContainer->handle($container),
            ),
        ];

        if (array_sum($deleted) > 0) {
            Log::info('Gallrade utgånget innehåll ur papperskorgen.', $deleted);
        }

        return $deleted;
    }

    /**
     * Gallrar en typ rad för rad. `chunkById` och inte `chunk` — raderna
     * försvinner under iterationen och pagingen måste följa primärnyckeln,
     * inte radnumret (Beslut 8).
     *
     * @template TModel of Item|Attachment|Category|Tag|Container
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(TModel): void  $perRow
     */
    private function purge(string $type, Builder $query, Closure $perRow): int
    {
        $deleted = 0;

        $query->chunkById(100, function ($rows) use ($type, $perRow, &$deleted): void {
            foreach ($rows as $row) {
                try {
                    // Den rättsliga spärren (issue 112), läst per rad och
                    // inte i urvalet: en spärr som sätts medan körningen pågår
                    // ska hinna få verkan. Ingen loggning när en rad hoppas
                    // över — en spärr är ett beslut och inte ett fel, och
                    // beslutet står i legal_hold.
                    $account = $this->owningAccount($row);

                    if ($account !== null && LegalHold::covers($account)) {
                        continue;
                    }

                    $perRow($row);
                    $deleted++;
                } catch (Throwable $e) {
                    Log::error('Kunde inte gallra utgånget innehåll ur papperskorgen.', [
                        'type' => $type,
                        'ulid' => $row->ulid,
                        'exception' => $e->getMessage(),
                    ]);
                }
            }
        });

        return $deleted;
    }

    /**
     * Kontot som äger raden. Containern bär sitt konto själv; allt annat i
     * papperskorgen hänger under en container som ägs av ett konto
     * ([[ADR-0002 Konto äger container]]), och bilagan når sin container
     * genom itemet.
     *
     * Båda leden läses med `withTrashed()`: en mjukraderad förälder är
     * precis vad den här gallringen tittar på, och Eloquents globala
     * SoftDeletes-scope hade gömt den — och därmed spärren. Null betyder
     * "ingen ägare att fråga om", och då gallras raden som förut.
     */
    private function owningAccount(Item|Attachment|Category|Tag|Container $row): ?Account
    {
        if ($row instanceof Container) {
            return $row->account;
        }

        $containerId = $row instanceof Attachment
            ? Item::withTrashed()->whereKey($row->item_id)->value('container_id')
            : $row->container_id;

        if ($containerId === null) {
            return null;
        }

        return Container::withTrashed()->whereKey($containerId)->first()?->account;
    }
}

<?php

namespace App\Actions\Trash;

use App\Exceptions\Api\ApiException;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Återställer hela ett tillfälle av agentraderingar — raderna som delar
 * `agent_deletion_batch` — se [[ADR-0087 MCP-server för agenter]] Riktning 8
 * och issue 406 § 3.
 *
 * **Bara batchen, ingenting annat.** Urvalet är kolumnen `agent_deletion_batch`
 * på de tabeller papperskorgen listar; ett item som användaren själv raderat
 * bredvid har en annan batch, eller ingen, och rörs inte. Retentionen gäller
 * som i papperskorgen: en rad vars `deleted_at` passerat gränsen finns inte
 * och återställs inte.
 *
 * **Samma actions som en enskild återställning.** Items, bilagor, kategorier
 * och taggar går genom App\Actions\Trash\RestoreContent, och en container
 * genom App\Actions\Trash\RestoreTrashedContainer — reglerna (föräldern i
 * papperskorgen, kategorins underträd, containerantalet, händelseloggen) bor
 * kvar där. Behörigheten är prövad av anroparen ([[ADR-0024 Tunna controllers
 * och actions]]): kontrollern skickar bara de rader användaren själv får
 * återställa.
 *
 * **Ordningen är containrar → items → kategorier → taggar → bilagor → relationer.**
 * Containern måste tillbaka först: ett item som raderades före sin container
 * hör till samma tillfälle, och `RestoreContent` läser itemets container när
 * den loggar raden. Bilagan prövar sitt item, så itemet måste tillbaka före
 * bilagan. En rad som ändå inte kan återställas — en bilaga vars item ligger
 * i en annan batch — hoppas över och räknas som `skipped`; resten av
 * tillfället återställs.
 */
class RestoreAgentDeletionBatch
{
    public function __construct(
        private readonly RestoreContent $restoreContent,
        private readonly RestoreTrashedContainer $restoreTrashedContainer,
    ) {}

    /**
     * Tillfällets rader, inom retentionen. Nycklarna är samma som `handle()`
     * tar emot, så kontrollern kan pröva grinden per rad och skicka tillbaka
     * exakt det som blev kvar.
     *
     * @return array{
     *     items: Collection<int, Item>,
     *     categories: Collection<int, Category>,
     *     tags: Collection<int, Tag>,
     *     attachments: Collection<int, Attachment>,
     *     containers: Collection<int, Container>,
     *     links: Collection<int, ItemLink>,
     * }
     */
    public function models(string $batch): array
    {
        $cutoff = now()->subDays((int) config('files.trash_retention_days'));

        return [
            'items' => Item::query()->onlyTrashed()
                ->where('agent_deletion_batch', $batch)
                ->where('deleted_at', '>=', $cutoff)
                ->get(),
            'categories' => Category::query()->onlyTrashed()
                ->where('agent_deletion_batch', $batch)
                ->where('deleted_at', '>=', $cutoff)
                ->get(),
            'tags' => Tag::query()->onlyTrashed()
                ->where('agent_deletion_batch', $batch)
                ->where('deleted_at', '>=', $cutoff)
                ->get(),
            'attachments' => Attachment::query()->onlyTrashed()
                ->where('agent_deletion_batch', $batch)
                ->where('deleted_at', '>=', $cutoff)
                ->get(),
            'containers' => Container::query()->onlyTrashed()
                ->where('agent_deletion_batch', $batch)
                ->where('deleted_at', '>=', $cutoff)
                ->get(),
            'links' => ItemLink::query()->onlyTrashed()
                ->where('agent_deletion_batch', $batch)
                ->where('deleted_at', '>=', $cutoff)
                ->get(),
        ];
    }

    /**
     * @param  array{
     *     items: Collection<int, Item>,
     *     categories: Collection<int, Category>,
     *     tags: Collection<int, Tag>,
     *     attachments: Collection<int, Attachment>,
     *     containers: Collection<int, Container>,
     *     links: Collection<int, ItemLink>,
     * }  $models
     * @return array{restored: int, skipped: int}
     */
    public function handle(User $actor, array $models): array
    {
        // Hela svepet i EN transaktion. De enskilda återställningarna öppnar
        // egna transaktioner, som här blir sparpunkter: en rad som avvisas
        // rullas tillbaka för sig och resten av tillfället går igenom. Utan
        // den yttre transaktionen hade ett fel mitt i lämnat en delvis
        // återställd batch utan att någon kunde säga vilken halva som gällt.
        return DB::transaction(function () use ($actor, $models): array {
            $restored = 0;
            $skipped = 0;

            // Containrarna först: ett item som raderades innan sin container
            // hör till samma tillfälle, och RestoreContent läser itemets
            // container när den loggar raden — den måste vara levande igen då.
            // Containern hanteras som varje annan rad: ett domänfel (till
            // exempel containerkvoten) hoppas över i stället för att avbryta
            // svepet (granskningsfynd 4).
            foreach ($models['containers'] as $container) {
                [$restored, $skipped] = $this->restoreOne($restored, $skipped, fn () => $this->restoreTrashedContainer->handle($container, $actor));
            }

            foreach ($models['items'] as $item) {
                [$restored, $skipped] = $this->restoreOne($restored, $skipped, fn () => $this->restoreContent->handle('item', $item, $actor));
            }

            [$categoryRestored, $skipped] = $this->restoreCategories($models['categories'], $actor, $skipped);
            $restored += $categoryRestored;

            foreach ($models['tags'] as $tag) {
                [$restored, $skipped] = $this->restoreOne($restored, $skipped, fn () => $this->restoreContent->handle('tag', $tag, $actor));
            }

            foreach ($models['attachments'] as $attachment) {
                [$restored, $skipped] = $this->restoreOne($restored, $skipped, fn () => $this->restoreContent->handle('attachment', $attachment, $actor));
            }

            // Relationerna sist (ADR-0097 § 8): en relation kan bara komma
            // tillbaka när båda ändarna gör det, så items och containrar måste
            // vara levande igen. Prövningen är RestoreContent:s — en relation
            // vars ände ligger i en annan batch, eller vars par under tiden
            // länkats på nytt, räknas som `skipped`.
            foreach ($models['links'] as $link) {
                [$restored, $skipped] = $this->restoreOne($restored, $skipped, fn () => $this->restoreContent->handle('link', $link, $actor));
            }

            return ['restored' => $restored, 'skipped' => $skipped];
        });
    }

    /**
     * Kategorierna kan hänga i en förälder i samma tillfälle. Passen går tills
     * inget mer kan återställas; barnet som möter en förälder i en annan batch
     * blir kvar som `skipped`, precis som i en enskild återställning.
     *
     * @param  Collection<int, Category>  $categories
     * @return array{0: int, 1: int} återställda och överhoppade
     */
    private function restoreCategories(Collection $categories, User $actor, int $skipped): array
    {
        $restored = 0;
        $kvar = $categories->all();

        do {
            $progress = false;

            foreach ($kvar as $index => $category) {
                try {
                    $this->restoreContent->handle('category', $category, $actor);
                } catch (ApiException) {
                    continue;
                }

                $restored++;
                $progress = true;
                unset($kvar[$index]);
            }
        } while ($progress && $kvar !== []);

        return [$restored, $skipped + count($kvar)];
    }

    /**
     * En återställning som kan möta en förälder i papperskorgen
     * (`trash.parent_deleted`): raden hoppas då över och resten av tillfället
     * återställs.
     *
     * @param  callable(): void  $restore
     * @return array{0: int, 1: int}
     */
    private function restoreOne(int $restored, int $skipped, callable $restore): array
    {
        try {
            $restore();
            $restored++;
        } catch (ApiException) {
            $skipped++;
        }

        return [$restored, $skipped];
    }
}

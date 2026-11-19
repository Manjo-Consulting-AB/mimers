<?php

namespace App\Actions\Template;

use App\Actions\Category\CreateCategory;
use App\Actions\Field\CreateField;
use App\Actions\Field\FieldRules;
use App\Exceptions\Api\ApiException;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tillämpar en mall — eller ett innehåll i samma form, som frontendens
 * inbyggda förslag — på en befintlig container, se
 * [[ADR-0089 Egna fält på items]] § Riktning 6 och 9 och issue 421 § 3.
 *
 * **Mallen är en kopia.** Innehållet läses ur `$template->content` (eller ur
 * `$content` när det inbyggda förslaget skickas in) och skrivs in som
 * vanliga kategorier och fält. Ingenting i containern pekar tillbaka på
 * mallen, och en senare ändring i mallen rör den inte.
 *
 * **Fält vars rubrik redan finns hoppas över**, utan hänsyn till skiftläge —
 * jämförelsen går genom databasens collation, samma som
 * App\Actions\Field\FieldRules::assertLabelAvailable(). Det gäller också
 * rubriker som redan lagts till av ett tidigare fält i SAMMA mall, så en
 * mall med två varianter av samma rubrik inte fäller tillämpningen mitt i.
 * Rubriken jämförs mot allt som SYNS på ett item (`visibleFields()`), inte
 * bara containerns fält: ett eget fält på ett item med samma rubrik hade
 * annars fått `CreateField` att kasta `field.label_taken` och rullat tillbaka
 * hela tillämpningen.
 *
 * **Gränsen prövas innan något skrivs.** Skulle resultatet gå över
 * `fields.max_per_container` avvisas hela tillämpningen med 422
 * `field.limit_reached`, och varken kategorier eller fält ändras — kontrollen
 * ligger före den första skrivningen, inte bara i transaktionen.
 *
 * **Kategorierna läggs till som kategoriförslagen i dag**: rot först, sedan
 * barnen i den ordning listan kommer, med `CreateCategory` som sätter
 * positionen. Till skillnad från förslagsruttten krävs ingen tom container —
 * mallen läggs ovanpå det som redan finns.
 *
 * **Ingen `Gate::authorize()`.** Anroparen prövar skrivrätten i containern
 * (issue 421 § 4).
 */
final class ApplyTemplate
{
    public function __construct(
        private readonly CreateCategory $createCategory,
        private readonly CreateField $createField,
    ) {}

    /**
     * @param  array<string, mixed>|null  $content  Innehållet när ingen sparad
     *                                              mall används (inbyggt förslag).
     * @return array{categories: int, fields: int, skipped: list<string>}
     *
     * @throws ApiException 422 `field.limit_reached` när tillämpningen skulle
     *                      gå över containerns fältgräns. Ingenting ändras.
     */
    public function handle(Container $container, ?ContainerTemplate $template, ?array $content, User $actor): array
    {
        $content = $template->content ?? $content ?? [];

        /** @var list<array<string, mixed>> $categories */
        $categories = $content['categories'] ?? [];

        /** @var list<array<string, mixed>> $fields */
        $fields = $content['fields'] ?? [];

        return DB::transaction(function () use ($container, $categories, $fields, $actor): array {
            // Serialiserar tillämpningar och enskilda fältskapanden mot samma
            // container — samma radlås som App\Actions\Field\CreateField tar.
            Container::query()->whereKey($container->getKey())->lockForUpdate()->first();

            [$toCreate, $skipped] = $this->fieldsToCreate($container, $fields);

            $limit = FieldRules::max(null);

            if (FieldRules::fieldsInScope($container, null)->count() + count($toCreate) > $limit) {
                throw ApiException::make('field.limit_reached', [
                    'limit' => $limit,
                    'scope' => FieldRules::scope(null),
                ], 422);
            }

            $categoriesCreated = $this->applyCategories($container, $categories, null);

            $fieldsCreated = 0;

            foreach ($toCreate as $field) {
                $this->createField->handle(
                    $container,
                    null,
                    $field['label'],
                    $field['type'],
                    null,
                    $actor,
                );
                $fieldsCreated++;
            }

            return [
                'categories' => $categoriesCreated,
                'fields' => $fieldsCreated,
                'skipped' => $skipped,
            ];
        });
    }

    /**
     * Delar mallens fält i dem som ska skapas och dem som hoppas över för att
     * rubriken redan finns. Läser bara — den som anropar kontrollerar gränsen
     * och skriver.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array{0: list<array{label: string, type: string}>, 1: list<string>}
     */
    private function fieldsToCreate(Container $container, array $fields): array
    {
        $toCreate = [];
        $skipped = [];

        /** @var array<string, true> $seen */
        $seen = [];

        foreach ($fields as $field) {
            $label = trim((string) $field['label']);
            $key = mb_strtolower($label, 'UTF-8');

            $exists = isset($seen[$key])
                || FieldRules::visibleFields($container, null)->where('label', $label)->exists();

            if ($exists) {
                $skipped[] = $label;

                continue;
            }

            $seen[$key] = true;
            $toCreate[] = ['label' => $label, 'type' => (string) $field['type']];
        }

        return [$toCreate, $skipped];
    }

    /**
     * Skapar kategoriträdet i ordning: roten först, sedan barnen.
     *
     * `null` som aktör till App\Actions\Category\CreateCategory: kategorierna
     * är följden av användarens handling, och rollen skriver EN rad för
     * tillämpningen och ingen per kategori — samma val som den färdiga
     * kategorimallen (App\Http\Controllers\CategoryController::storePreset()).
     * Betalaren blir ändå rätt: App\Support\Usage\Payer faller tillbaka på den
     * inloggade användaren när aktören är null.
     *
     * @param  list<array<string, mixed>>  $categories
     */
    private function applyCategories(Container $container, array $categories, ?Category $parent): int
    {
        $created = 0;

        foreach ($categories as $node) {
            $category = $this->createCategory->handle(
                $container,
                (string) $node['name'],
                $parent,
                null,
                null,
            );
            $created++;

            /** @var list<array<string, mixed>> $children */
            $children = $node['children'] ?? [];

            $created += $this->applyCategories($container, $children, $category);
        }

        return $created;
    }
}

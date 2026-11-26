<?php

namespace App\Actions\Template;

use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\User;
use App\Support\Usage\MetadataLedger;
use App\Support\Usage\Payer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Sparar en containers kategoriträd och fält som en mall på kontot, se
 * [[ADR-0089 Egna fält på items]] § Riktning 6 och 9 och issue 421 § 3.
 *
 * **Bara containerns eget innehåll.** Kategorierna läses levande och i
 * `position`-ordning; fälten är containerns (de med `item_id` NULL), också de
 * i ordning. Inga items och inga egna fält på items följer med — mallen bär
 * det som hör till containern, inte dess innehåll.
 *
 * **Agentreglerna följer med** när `$includeAgentRules` är sant och
 * `manageAgentRules` tillåter det ([[ADR-0101 Mallarna]] § Beslut 5); annars
 * sparas `agent_rules` som `null`.
 *
 * **Mallen ägs av containerns konto**, och betalaren är det agerande kontot
 * genom App\Support\Usage\Payer — samma regel som för en rad skaparen själv
 * skriver ([[ADR-0081 All lagrad data räknas mot kvoten]] § 3). Raden skrivs
 * genom App\Support\Usage\MetadataLedger, så `metadata_bytes` bokförs och
 * kvoten prövas i samma transaktion som raden.
 *
 * **Taket är 50 mallar per konto** (`templates.max_per_account`). Kontoraden
 * låses först, så att två samtidiga sparanden serialiseras: annars kunde
 * båda se en ledig plats och den 51:a smita igenom. Bara levande mallar
 * räknas — en mall i papperskorgen upptar ingen plats.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen
 * (App\Http\Controllers\Api\ContainerTemplateController): `view` i containern
 * och medlemskap i dess konto, se issue 421 § 4 — samma linje som
 * App\Actions\Category\CreateCategory.
 */
final class SaveContainerTemplate
{
    /**
     * @throws ApiException 422 `template.limit_reached` när kontot redan har
     *                      `templates.max_per_account` levande mallar.
     */
    public function handle(User $actor, Container $container, string $name, bool $includeAgentRules = true): ContainerTemplate
    {
        $content = $this->contentOf($container);

        // Reglerna följer bara med när den som sparar får skriva dem
        // (ContainerPolicy::manageAgentRules()); för alla andra blir de `null`
        // oavsett vad som begärts ([[ADR-0101 Mallarna]] § Beslut 5).
        $content['agent_rules'] = $includeAgentRules && Gate::forUser($actor)->allows('manageAgentRules', $container)
            ? $this->normalisedRules($container->agent_rules)
            : null;

        return DB::transaction(function () use ($actor, $container, $name, $content): ContainerTemplate {
            // Radlåset serialiserar räkningen av mallar för kontot.
            $account = Account::query()->whereKey($container->account_id)->lockForUpdate()->firstOrFail();

            $limit = (int) config('templates.max_per_account');

            if (ContainerTemplate::query()->where('account_id', $account->id)->count() >= $limit) {
                throw ApiException::make('template.limit_reached', ['limit' => $limit], 422);
            }

            $template = new ContainerTemplate([
                'name' => trim($name),
                'content' => $content,
            ]);
            $template->account_id = $account->id;
            $template->billed_account_id = Payer::idFor($actor, $container);

            MetadataLedger::save($template);

            return $template;
        });
    }

    /**
     * Mallens innehåll: kategoriträdet och containerns fält, i ordning.
     *
     * `$container->categories()` och `$container->fields()` bär själva
     * ordningen och det globala SoftDeletes-scopet, så ett raderat fält eller
     * en raderad kategori aldrig hamnar i en mall.
     *
     * `agent_rules` är `null` här; handle() fyller i den efter behörighetsprövningen.
     *
     * @return array{categories: list<array<string, mixed>>, fields: list<array{label: string, type: string}>, agent_rules: string|null}
     */
    public function contentOf(Container $container): array
    {
        $categories = $container->categories()->orderBy('position')->orderBy('id')->get();

        $fields = $container->fields()
            ->get()
            ->map(fn ($field): array => ['label' => $field->label, 'type' => $field->type])
            ->all();

        return [
            'categories' => $this->tree($categories),
            'fields' => $fields,
            'agent_rules' => null,
        ];
    }

    private function normalisedRules(?string $rules): ?string
    {
        $rules = $rules === null ? '' : trim($rules);

        return $rules === '' ? null : $rules;
    }

    /**
     * Bygger det nästlade trädet ur den platta listan. Syskonens ordning är
     * den frågan gav — `position` med `id` som tiebreak.
     *
     * Grupperingen är en vanlig array: `Collection::groupBy()` på en Eloquent
     * Collection ger en Eloquent Collection av Eloquent Collections, och den
     * formen säger att grupperna är modeller fast de inte är det. En array
     * säger precis vad den är.
     *
     * @param  Collection<int, Category>  $categories
     * @return list<array<string, mixed>>
     */
    private function tree(Collection $categories): array
    {
        /** @var array<int, list<Category>> $byParent */
        $byParent = [];

        foreach ($categories as $category) {
            $byParent[(int) $category->parent_id][] = $category;
        }

        return $this->branch($byParent, 0);
    }

    /**
     * @param  array<int, list<Category>>  $byParent
     * @return list<array<string, mixed>>
     */
    private function branch(array $byParent, int $parentId): array
    {
        $nodes = [];

        foreach ($byParent[$parentId] ?? [] as $category) {
            /** @var array<string, mixed> $node */
            $node = ['name' => $category->name];
            $grandchildren = $this->branch($byParent, (int) $category->getKey());

            if ($grandchildren !== []) {
                $node['children'] = $grandchildren;
            }

            $nodes[] = $node;
        }

        return $nodes;
    }
}

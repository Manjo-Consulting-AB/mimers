<?php

namespace App\Http\Requests\Template;

use App\Models\Category;
use App\Models\ItemField;
use Illuminate\Validation\Rule;

/**
 * Valideringsreglerna för en malls innehåll, delade av
 * App\Http\Requests\Template\ApplyTemplateRequest och
 * App\Http\Requests\Container\StoreContainerRequest — samma JSON ska
 * avvisas likadant oavsett om den kommer som `template_content` vid
 * containerns skapande eller vid en tillämpning (issue 421 § 3 och 5).
 *
 * Formen är `{"categories": [{"name", "children": [...]}], "fields":
 * [{"label", "type"}]}` enligt [[Items och organisation]] § container_template.
 *
 * **`agent_rules`** är ren text, högst 2 000 tecken som containerns fält
 * ([[ADR-0100 Agentregler]]); en gammal mall utan nyckeln läses som `null`.
 *
 * **Djupet är kategoriträdets**, `Category::MAX_DEPTH` nivåer. Ett `children`
 * på den sista nivån är `prohibited`, så ett för djupt träd blir ett
 * valideringsfel i stället för en halv tillämpning.
 *
 * **Fälten är högst `fields.max_per_container`** — fler fält än en container
 * får bära kan en mall aldrig behöva, och taket stänger samma missbruksvektor
 * som App\Http\Requests\Category\StoreCategoryPresetRequest gör för
 * kategoriförslagen ([[ADR-0017 Missbruksvektorer]]).
 *
 * **Kategorierna har ett TOTALT tak**, `templates.max_categories` noder över
 * hela trädet. Per-nivå-taken (100 rötter, 100 barn per nod, fem nivåer) räcker
 * inte som missbruksgräns: de multipliceras med varandra. Det totala taket
 * prövas av App\Actions\Template\ApplyTemplate före den första skrivningen,
 * eftersom både `template_content` och en sparad malls innehåll går genom
 * samma action — se config/templates.php.
 */
final class TemplateContentRules
{
    /**
     * @return array<string, mixed>
     */
    public static function rules(string $prefix = ''): array
    {
        $rules = [
            "{$prefix}categories" => ['sometimes', 'array', 'max:100'],
            "{$prefix}agent_rules" => ['sometimes', 'nullable', 'string', 'max:2000'],
            "{$prefix}fields" => ['sometimes', 'array', 'max:'.config('fields.max_per_container')],
            "{$prefix}fields.*" => ['array'],
            "{$prefix}fields.*.label" => ['required', 'string', 'min:1', 'max:100'],
            "{$prefix}fields.*.type" => ['required', 'string', Rule::in(ItemField::TYPES)],
        ];

        $path = "{$prefix}categories.*";

        for ($level = 1; $level <= Category::MAX_DEPTH; $level++) {
            $rules[$path] = ['array'];
            $rules["{$path}.name"] = ['required', 'string', 'min:1', 'max:255'];
            $rules["{$path}.children"] = $level >= Category::MAX_DEPTH
                ? ['prohibited']
                : ['sometimes', 'array', 'max:100'];

            $path .= '.children.*';
        }

        return $rules;
    }
}

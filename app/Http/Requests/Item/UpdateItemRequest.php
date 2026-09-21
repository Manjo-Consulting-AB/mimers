<?php

namespace App\Http\Requests\Item;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /api/containers/{container}/items/{item}, see issue 13a § Beslut
 * 6 and 7 and issue 13b § Beslut 4 and 5.
 *
 * Every documented field is optional (`sometimes`). `category` changes
 * only when the KEY is present in the body — the controller reads
 * `$request->has('category')`, never `filled()`: an omitted `category`
 * means "leave it alone", while `category: null` explicitly clears the
 * category, see § Beslut 7.
 *
 * `notes` (issue 96) is `sometimes` + `nullable` like `description`: an
 * omitted `notes` leaves the column alone, while `notes: null` EMPTIES it.
 * The three states — unset, set, emptied — are all reachable, and the field
 * is never filled from `description` or the other way round.
 *
 * `tags` works the same way but is NOT nullable: a present `tags` REPLACES
 * the whole set (issue 13b § Beslut 4) — `tags: []` clears it, an omitted
 * `tags` leaves it alone. The controller reads `has('tags')`, never
 * `filled()`, which is false for an empty array. Each tag-ULID must exist
 * in the container's own tag list and not be soft-deleted, same rule and
 * same leak rationale as StoreItemRequest (issue 13b § Beslut 5); the whole
 * list is resolved in ONE query and checked in memory, see § Beslut 6.
 *
 * `account` is deliberately absent from the rules below — neither
 * `created_by_account_id` nor `created_by_user_id` can be changed with
 * PATCH. Who created the row is history; an `account` (or `created_by_*`)
 * field sent in a PATCH body is silently ignored by `validated()`, see §
 * Beslut 6.
 *
 * `cover` — itemets omslagsbild (issue 93 · [[ADR-0041 Itemets vy]]
 * § Beslut) — finns bara på WEBBENS rutt. Fältet är ett val i
 * redigeringsformuläret, och `/api` har inte bett om det: samma linje som
 * `variants` i issue 61b § Beslut 1 och kategorinamnet i issue 57a
 * § Beslut 6, som ligger bredvid resursen i stället för i den. PATCH finns på
 * båda ruttrena, så det är RUTTEN och inte metoden som skiljer dem, samma
 * grepp som `$onContainerRoute` i IndexItemRequest. Utan grenen hade `/api`
 * tagit emot ett fält som `Item::fill()` sedan kastar — en validering som
 * lovar en skrivning ingen gör.
 *
 * Värdet är bilagans ULID, och regeln är hela "Klart när"-listan i ett
 * villkor: bilagan måste FINNAS, höra till DET HÄR itemet, vara en BILD och
 * inte vara mjukraderad. Ett annat items bilaga och en PDF ger därför samma
 * svar som en påhittad ULID — 422 på fältet, aldrig ett tyst nej och aldrig
 * en trasig bild i vyn. Läckaget — att felet skiljer en ULID som finns från en
 * som inte gör det — är detsamma som för `category` och `tags`, och samma
 * resonemang gäller: den som redan håller en 26-tecken-ULID får veta att hon
 * håller den (issue 13b § Beslut 5).
 *
 * `sometimes` OCH `nullable`: ett UTELÄMNAT `cover` lämnar pekaren orörd,
 * medan ett uttryckligen skickat `cover: null` RENSAR den — upplösningen
 * faller då tillbaka på itemets äldsta bild. Skillnaden är inte kosmetisk:
 * väljaren ritas bara när itemet har bilder (ett val mellan ett alternativ är
 * ingen fråga), så ett item vars enda bild ligger i papperskorgen får inget
 * fält alls. Utan `sometimes` hade varje namnbyte på ett sådant item skickat
 * `cover: null` och tyst raderat ett val som [[ADR-0008 Soft delete och
 * papperskorg]] lovar ska gå att återställa — kommer bilagan tillbaka ska
 * omslaget göra det med. Samma skillnad mellan "rör det inte" och "töm det"
 * som `category` bär för `/api`.
 */
class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() decides authorization in
        // the controller, not here — see issue 13a § Beslut 2.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $validTagUlids = [];

        if (is_array($tags = $this->input('tags')) && $tags !== []) {
            $tagUlids = array_filter($tags, 'is_string');

            if ($tagUlids !== []) {
                $validTagUlids = Tag::whereIn('ulid', $tagUlids)
                    ->where('container_id', $this->route('container')->id)
                    ->whereNull('deleted_at')
                    ->pluck('ulid')
                    ->all();
            }
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'model' => ['sometimes', 'nullable', 'string', 'max:255'],
            'serial_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'purchased_at' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'warranty_until' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'position_note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'category' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('category', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
            'tags' => ['sometimes', 'array'],
            'tags.*' => ['string', Rule::in($validTagUlids)],
        ];

        // Omslagsbilden finns bara i webbens redigeringsformulär, se
        // klassens docblock. `{item}` är redan löst av scopeBindings(), så
        // regeln kan pröva bilagan mot DET HÄR itemet.
        if ($this->routeIs('containers.items.update')) {
            $rules['cover'] = [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('attachment', 'ulid')->where(
                    fn ($query) => $query
                        ->where('item_id', $this->route('item')->id)
                        ->where('kind', 'image')
                        ->whereNull('deleted_at')
                ),
            ];
        }

        return $rules;
    }
}

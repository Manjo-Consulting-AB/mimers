<?php

namespace App\Http\Requests\Category;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/containers/{container}/categories, se issue 11 § Beslut 3, 6,
 * 10 och 11. Kroppen är `{"name", "position"?, "parent"?}`.
 *
 * `parent` är en kategori-ULID, uppslagen INOM den container rutten redan
 * bär (`$this->route('container')`) — en ULID som finns men hör till en
 * annan container, eller en mjukraderad rad, är alltså ett
 * VALIDERINGSFEL (422 `validation.failed`, fältkoden `validation.exists`
 * på `parent`), inte ett 404 och inte ett behörighetsfel. Se issue 11 §
 * Beslut 10, samma gränsdragning som `StoreContainerRequest` gör för
 * `account`. `whereNull('deleted_at')` går förbi Eloquents globala
 * SoftDeletes-scope, som `Rule::exists` inte känner till.
 *
 * Utelämnat `parent` (eller uttryckligt `null`) betyder rotkategori — inget
 * `sometimes` här, till skillnad från UpdateCategoryRequest: det finns
 * ingen befintlig förälder att bevara vid skapande, se § Beslut 10.
 *
 * `position` är valfri — utelämnad sätter App\Actions\Category\MoveCategory/
 * App\Http\Controllers\Api\CategoryController::store() den till
 * `max(position)` bland syskonen plus ett, se § Beslut 6. Ingen
 * `min:0`-regel — `position` är signerad INT, dokumentet ställer inget
 * sådant krav, se § Att se upp med.
 *
 * `name` är fritext utan unikhetskrav, se § Beslut 11. Trimmas redan av
 * Laravels globala `TrimStrings`-middleware.
 */
class StoreCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här — se issue 11 § Beslut 2.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'position' => ['nullable', 'integer'],
            'parent' => [
                'nullable',
                'string',
                Rule::exists('category', 'ulid')->where(
                    fn ($query) => $query->where('container_id', $this->route('container')->id)->whereNull('deleted_at')
                ),
            ],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Item;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Kroppen för flytt och kopiering av en bilaga: `{"target": "<item-ulid>"}` —
 * se [[ADR-0053 Flytt och kopiering]] § 8 och
 * App\Http\Controllers\AttachmentController::move()/copy().
 *
 * Målet är ett item och kan ligga i en ANNAN container än den ruttens
 * `{container}` pekar på, så det går inte att lösa med `scopeBindings()`:
 * ULID:n slås upp globalt. Uppslaget sker mot `item`-tabellen genom modellen,
 * så SoftDeletes' globala scope gäller — en mjukraderad måltavla finns inte
 * och ger samma svar som en som aldrig funnits: **404**.
 *
 * Att målet inte FINNS är alltså 404 och inte ett valideringsfel. Det är
 * skillnaden mot App\Http\Requests\Trash\RestoreRequest, där en ULID ur fel
 * container är 422: där är målet bundet till en container kroppen pekar ut,
 * här är det ett fristående item som anroparen antingen når (då avgör
 * `ItemPolicy::create()` i kontrollern) eller inte.
 *
 * Behörigheten prövas inte här — `authorize()` svarar ja — utan i kontrollern,
 * där både källans och målets grindar finns på samma ställe.
 */
class TargetItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'target' => ['required', 'string'],
        ];
    }

    /**
     * Målet, uppslaget på ULID. `firstOrFail()` ger 404 för både en ULID som
     * inte finns och en som är mjukraderad — SoftDeletes-scopet ligger på
     * modellen, inte i den här frågan, så de två inte kan glida isär.
     */
    public function targetItem(): Item
    {
        return Item::query()
            ->where('ulid', (string) $this->string('target'))
            ->firstOrFail();
    }
}

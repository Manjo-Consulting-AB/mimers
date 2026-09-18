<?php

namespace App\Http\Requests\Container;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/containers/{container}, se issue 8 § Beslut 9: PATCH tar bara
 * emot `name` och `kind`, båda valfria (`sometimes`). Varken `account`
 * eller `account_id` finns i reglerna nedan — ett klientskickat sådant
 * fält är alltså inte med i `validated()` och ändrar aldrig ägaren. Att
 * flytta en container mellan konton är ägarbyte, issue 39.
 *
 * `kind` är fritt och frivilligt sedan issue 84 · [[ADR-0036 Containerns
 * art]]: reglerna är längd och format, aldrig medlemskap i en lista.
 *
 * **Den som tömmer fältet skriver den tomma strängen, inte `null`.**
 * `ConvertEmptyStringsToNull` gör en tom ruta till `null` innan reglerna
 * körs, och kolumnen är NOT NULL — normaliseringen nedan vänder tillbaka
 * den till `''` så att `validated()` bär samma värde som skapandet sparar
 * (se StoreContainerRequest). Nyckeln som SAKNAS rörs inte: `sometimes` ska
 * fortsätta betyda "ändra inte arten".
 */
class UpdateContainerRequest extends FormRequest
{
    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::update() avgör behörighet i
        // kontrollern, inte här.
        return true;
    }

    /**
     * En nyckel som FINNS men är `null` betyder "användaren tömde rutan" —
     * den blir den tomma strängen. En nyckel som saknas lämnas orörd, så
     * `sometimes` fortfarande skiljer "töm" från "rör inte".
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('kind') && $this->input('kind') === null) {
            $this->merge(['kind' => '']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', 'string', 'max:40'],
        ];
    }
}

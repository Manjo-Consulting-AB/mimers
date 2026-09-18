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
 * **Den som tömmer fältet lagrar `null`** — `ConvertEmptyStringsToNull` gör en
 * tom ruta till `null` innan reglerna körs, och kolumnen är nullbar sedan issue
 * 84, så `validated()` bär samma värde som skapandet sparar (se
 * StoreContainerRequest). Blanksteg trimmas bort vid inmatningen; ett fält som
 * bara var blanksteg blir därmed också `null`. Nyckeln som SAKNAS rörs inte:
 * `sometimes` ska fortsätta betyda "ändra inte arten".
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
     * Blanksteg trimmas och ett tomt värde blir `null` — se
     * klassdokumentationen. En nyckel som SAKNAS lämnas orörd, så `sometimes`
     * fortfarande skiljer "töm arten" från "rör den inte".
     */
    protected function prepareForValidation(): void
    {
        if (! $this->has('kind')) {
            return;
        }

        $kind = $this->input('kind');

        if (is_string($kind)) {
            $kind = trim($kind);

            $this->merge(['kind' => $kind === '' ? null : $kind]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'kind' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }
}

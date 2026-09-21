<?php

namespace App\Http\Requests\Container;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/containers/{container}, se issue 8 § Beslut 9: PATCH tar bara
 * emot `name`, `kind`, `description` — sedan issue 88 · [[ADR-0039
 * Containerns översikt]] — och `currency` (issue 85 · [[ADR-0037 Valutans
 * arv]]), alla valfria (`sometimes`). Varken `account` eller
 * `account_id` finns i reglerna nedan — ett klientskickat sådant fält är
 * alltså inte med i `validated()` och ändrar aldrig ägaren. Att flytta en
 * container mellan konton är ägarbyte, issue 39.
 *
 * `kind` är fritt och frivilligt sedan issue 84 · [[ADR-0036 Containerns
 * art]]: reglerna är längd och format, aldrig medlemskap i en lista.
 *
 * **Den som tömmer ett fält lagrar `null`** — `ConvertEmptyStringsToNull` gör
 * en tom ruta till `null` innan reglerna körs, och båda kolumnerna är nullbara
 * (issue 84 och 85), så `validated()` bär samma värde som skapandet sparar (se
 * StoreContainerRequest). Blanksteg trimmas bort vid inmatningen; ett fält som
 * bara var blanksteg blir därmed också `null`. Nyckeln som SAKNAS rörs inte:
 * `sometimes` ska fortsätta betyda "ändra inte arten" — och för valutan
 * betyder en tömd ruta att containern återgår till att ÄRVA kontots valuta,
 * vilket är hela skillnaden mellan `null` och en egen valuta
 * (App\Models\Container::effectiveCurrency()).
 *
 * **Valutan normaliseras till versaler** och formas som `alpha|size:3`, inte
 * mot en lista — samma regel och samma skäl som i App\Http\Requests\Cost\
 * StoreCostEntryRequest: en valuta är ingen uppräkning, och en lista i koden
 * vore domänen inbyggd i den ([[ADR-0033 Produktens omfång]]).
 *
 * **Containerns valuta ändrar aldrig en skriven kostnadsrad.**
 * `cost_entry.currency` rörs inte av den här requesten, av kontrollern eller
 * av någon migration i issue 85 — det som står i en rad är vad som betalades
 * ([[ADR-0037 Valutans arv]] § Beslut).
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
     *
     * Valutan går samma väg och normaliseras dessutom till versaler, så en
     * klient som skickar `sek` lagrar `SEK` och en som tömmer rutan lagrar
     * `null` — containern ärver då kontots valuta.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('kind')) {
            $kind = $this->input('kind');

            if (is_string($kind)) {
                $kind = trim($kind);

                $this->merge(['kind' => $kind === '' ? null : $kind]);
            }
        }

        if ($this->has('currency')) {
            $currency = $this->input('currency');

            if (is_string($currency)) {
                $currency = mb_strtoupper(trim($currency));

                $this->merge(['currency' => $currency === '' ? null : $currency]);
            }
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
            // Frivillig och tömbar (issue 88 · [[ADR-0039 Containerns
            // översikt]]). Kolumnen är TEXT, som `item.description`, och har
            // därför ingen `max:255` att pröva mot: regeln är format och
            // ingenting annat. En nyckel som SAKNAS rör inte beskrivningen —
            // `sometimes` skiljer "töm den" från "ändra den inte".
            'description' => ['sometimes', 'nullable', 'string'],
            'currency' => ['sometimes', 'nullable', 'string', 'alpha', 'size:3'],
        ];
    }
}

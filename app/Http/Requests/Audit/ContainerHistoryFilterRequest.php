<?php

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validerar historikflitens filterfält, issue 179 · [[ADR-0050
 * Desktopdesignen]] § 17. Filtren står i querysträngen på
 * `GET /containers/{container}/history` (Beslut 4): `type`, `user`, `item`,
 * `from` och `to`.
 *
 * **Formfel, inte felkoder.** Webben kör Inertia och behåller Laravels vanliga
 * valideringsfel ([[ADR-0020 Plattformsidentitet och frontendgräns]]
 * § Konsekvenser) — svaret på ett ogiltigt datum är en redirect tillbaka med
 * felet på fältet, inte API:ets `validation.failed`. Samma form som
 * App\Http\Requests\Cost\ContainerCostFilterRequest.
 *
 * **Existensen prövas INTE, och det är Beslut 2.** Ett `exists`-krav på
 * `user` hade svarat olika på en användare som finns och en som inte gör det —
 * och därmed avslöjat vem som finns i loggen. Filtret på en användare vars
 * rader man inte får läsa ska ge en TOM lista, och samma svar som en användare
 * som inte finns; prövningen av existens ligger därför i läsregeln
 * (App\Actions\Audit\ListAuditEvents), som redan vet vad användaren får se.
 * Samma linje för `item`: loggen överlever itemet ([[ADR-0043 Tre loggar]]
 * § Händelseloggen), och ett `exists`-krav hade nekat frågan om ett item som
 * gallrats — precis den fråga en historik finns för.
 *
 * **Det som prövas är FORMEN.** `type` är ett domännamn ur ett öppet namnrum
 * (`audit_log.subject_type`, [[ADR-0043 Tre loggar]] § Händelseloggen), och
 * namnen följer `[a-z][a-z_]*`. `user` och `item` är ULID:er, tjugosex tecken
 * Crockford base32. Ett värde som inte har formen är ett fält någon skrivit
 * fel i, och det är ett formulärfel; ett värde som har formen men inte finns
 * är en fråga som får ett tomt svar.
 *
 * `to` ska inte kunna ligga före `from`. Regeln läggs bara när BÅDA finns:
 * utan `from` finns ingen undre gräns att jämföra mot, och en öppen gräns är
 * en giltig fråga (samma regel och samma skäl som CostReportRequest).
 */
class ContainerHistoryFilterRequest extends FormRequest
{
    /**
     * ULID: tjugosex tecken Crockford base32, versaler — samma form som
     * App\Models\Concerns\HasUlid skriver.
     */
    private const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    public function authorize(): bool
    {
        // App\Policies\ContainerPolicy::viewAuditLog() avgör behörigheten i
        // kontrollern, inte här.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'type' => ['nullable', 'string', 'max:40', 'regex:/^[a-z][a-z_]*$/'],
            'user' => ['nullable', 'string', 'regex:'.self::ULID],
            'item' => ['nullable', 'string', 'regex:'.self::ULID],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];

        if ($this->filled('from') && $this->filled('to')) {
            $rules['to'][] = 'after_or_equal:from';
        }

        return $rules;
    }
}

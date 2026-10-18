<?php

namespace App\Http\Requests\Trash;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * POST /trash/restore, se [[ADR-0065 Den samlade papperskorgen]] § 8 och
 * [[M39 Den samlade papperskorgen]] § 319.
 *
 * Kroppen är `{"type": "item|attachment|container", "ulid": "..."}` — samma
 * form som de två befintliga återställningarna (`RestoreRequest` för
 * containerns innehåll och `RestoreContainerRequest` för containern), men
 * med de tre slag den samlade papperskorgen listar. Kategorier och taggar
 * finns inte här: de hör till containerns egen papperskorg.
 *
 * Valideringen bevisar att ULID:en finns i typens tabell och är
 * mjukraderad. En levande rad, en okänd ULID eller ett okänt `type` är ett
 * valideringsfel — på webben ett fältfel, som i de befintliga rutterna
 * ([[ADR-0020 Plattformsidentitet och frontendgräns]] § Konsekvenser).
 *
 * **Utgångna rader faller INTE här.** `Rule::exists` ser dem, och att de
 * ändå inte går att återställa (404) avgörs av uppslaget i kontrollern —
 * exakt samma gränsdragning som `RestoreRequest` och
 * `RestoreContainerRequest` gör. Att filtrera på retention här hade gjort
 * ett utgånget innehåll till ett 422-valideringsfel.
 *
 * **`type` läses ur kroppen, aldrig ur querysträngen.** Listans filter har
 * samma namn (`/trash?type=…`, [[ADR-0065 Den samlade papperskorgen]] § 6),
 * och Laravels förval — `all()` — lägger kropp och querysträng ihop. Utan
 * avgränsningen hade en klient som postar bara `ulid` fått radens typ ur
 * filtret i adressen. `validationData()` ser därför bara kroppen, och
 * `ulidExistsRule()` läser samma källa. Inertia postar JSON, ett vanligt
 * formulär postar formdata; båda läses här.
 *
 * **Ingen behörighetslogik bor här.** Grinden prövas av kontrollern med
 * samma Action och samma grind som de befintliga rutterna
 * ([[ADR-0024 Tunna controllers och actions]]).
 */
class RestoreAnyRequest extends FormRequest
{
    /**
     * De tre slag den samlade papperskorgen räknar upp, i singular som
     * tabellnamnen.
     *
     * @var list<string>
     */
    public const TYPES = ['item', 'attachment', 'container'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Bara kroppen. Se docblocken: filtret i adressen får inte välja radtyp.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return $this->body();
    }

    /**
     * Kroppen utan querysträngen. Inertia skickar JSON, formulär skickar
     * formdata — `validationData()` och `ulidExistsRule()` läser samma källa.
     *
     * @return array<string, mixed>
     */
    private function body(): array
    {
        return $this->isJson()
            ? $this->json()->all()
            : $this->request->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(self::TYPES)],
            'ulid' => ['required', 'string', $this->ulidExistsRule()],
        ];
    }

    /**
     * `Rule::exists` mot typens tabell med `deleted_at IS NOT NULL`: raden
     * ska ligga i papperskorgen för att gå att återställa.
     */
    private function ulidExistsRule(): Exists
    {
        $table = match ($this->body()['type'] ?? null) {
            'item' => 'item',
            'attachment' => 'attachment',
            'container' => 'container',
            // Okänd typ: `type`-regeln rapporterar felet, men `ulid`-regeln
            // får inte krascha på en tabell som inte finns.
            default => null,
        };

        if ($table === null) {
            return Rule::exists('item', 'ulid')->where(fn ($query) => $query->whereRaw('1 = 0'));
        }

        return Rule::exists($table, 'ulid')->where(
            fn ($query) => $query->whereNotNull('deleted_at')
        );
    }
}

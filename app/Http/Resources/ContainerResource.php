<?php

namespace App\Http\Resources;

use App\Models\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Resursformatet för container, se issue 8 § Beslut 7 — blir prejudikat
 * för hela M1–M6. Löpnumret `id` exponeras aldrig; `account` är
 * ägarkontots ULID, inte dess löpnummer. Tidsstämplar i ISO 8601 med
 * UTC-offset (`toIso8601String()` — appens `config('app.timezone')` är
 * `UTC`, se AGENTS.md § Databaskonventioner "TIMESTAMP i UTC").
 *
 * Både enskild resurs (`ContainerController::show/store/update`) och lista
 * (`ContainerController::index`, via `ContainerResource::collection()`)
 * ligger under `data` — Laravels standardbeteende för `JsonResource` när
 * ingen egen `wrap`-nyckel satts, så en klient aldrig behöver två
 * avpackningsvägar.
 *
 * **`cover` kom med issue 159 · [[ADR-0047 Containerns bild]] § Beslut.** Det
 * är containerns bild, och den bor HÄR och inte bredvid resursen — tvärtemot
 * `can.update` i App\Http\Controllers\ContainerController::index(). Skälet är
 * att bilden ritas i containerns skal (resources/js/layouts/
 * ContainerLayout.vue), alltså på varje sida under layouten, och varje sådan
 * sida bär redan `container`-proppen ur den här resursen. Låg fältet bredvid
 * hade varje kontroller under layouten behövt lägga dit det, och en enda av
 * dem hade glömt — den sidans topprad hade tyst tappat bilden.
 *
 * Formen är `{ ulid, variants }` eller `null`, aldrig utelämnad. `variants` är
 * samma sak som `variants`-proppen på itemets detaljvy (issue 61b § Beslut 1):
 * de derivatvarianter som faktiskt FINNS, så att vyn kan rita miniatyren
 * `?variant=thumb` när den finns och originalet annars — en `<img>` mot en
 * variant som saknas är en trasig bild (issue 19a § Beslut 5), och en
 * nyuppladdad bild har inga derivat förrän kön har kört.
 *
 * **Relationen måste vara eager-laddad i listor.** `coverAttachment` och dess
 * `storedFile.derivatives` läses här, och en lista med N containers hade
 * annars blivit N+1. Se `->with(...)` i App\Http\Controllers\
 * ContainerController::index(), App\Http\Controllers\Api\ContainerController::
 * index() och App\Actions\Container\ListContainerSummaries.
 *
 * @mixin Container
 */
class ContainerResource extends JsonResource
{
    /**
     * Containerns bild i det format vyerna ritar ur, eller null när ingen är
     * satt — se klassens docblock.
     *
     * Publik och statisk därför att App\Actions\Container\ListContainerSummaries
     * bygger sina kort ur en egen fråga och inte genom den här resursen.
     * Kortet bär samma `cover` som containerlistan, och två formuleringar av
     * samma form hade glidit isär — samma skäl som `Container::
     * scopeAccessibleBy()` bär för urvalet.
     *
     * @return array{ulid: string, variants: list<string>}|null
     */
    public static function cover(Container $container): ?array
    {
        $bilaga = $container->coverAttachment;

        if ($bilaga === null) {
            return null;
        }

        return [
            'ulid' => $bilaga->ulid,
            // Sorterad: relationen har ingen egen ordning, och en prop vars
            // innehåll byter plats mellan två anrop mot samma rad är brus i
            // varje svar Inertia skickar (samma regel som `variants()` i
            // ItemController).
            'variants' => $bilaga->storedFile->derivatives
                ->pluck('variant')
                ->sort()
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Container $container */
        $container = $this->resource;

        return [
            'ulid' => $this->ulid,
            'name' => $this->name,
            'kind' => $this->kind,
            'cover' => self::cover($container),
            // ALLTID med, som `null` när den saknas — aldrig utelämnad. En
            // klient som måste skilja "saknas" från "tomt" ska inte behöva
            // hantera två fall (issue 8 § Beslut 7, och samma linje för
            // `kind` ovan).
            'description' => $this->description,
            'account' => $this->account->ulid,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}

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
 * Formen är `{ ulid, variants, focus }` eller `null`, aldrig utelämnad.
 * `focus` kom med issue 682 — den punkt på bilden som ska synas när ytan
 * beskär den, eller `null` för mitten (se `cover()`). `variants` är
 * samma sak som `variants`-proppen på itemets detaljvy (issue 61b § Beslut 1):
 * de derivatvarianter som faktiskt FINNS, så att vyn kan rita miniatyren
 * `?variant=thumb` när den finns och originalet annars — en `<img>` mot en
 * variant som saknas är en trasig bild (issue 19a § Beslut 5), och en
 * nyuppladdad bild har inga derivat förrän kön har kört.
 *
 * **Varje anropare eager-loadar relationen.** `cover` läses ovillkorligt av
 * `toArray()`, så varje kontroller som renderar den här resursen måste ha
 * laddat `coverAttachment.storedFile.derivatives` i förväg. Containrar UTAN
 * bild kostar ingenting — en nullbar främmande nyckel slår inte upp något
 * (BelongsTo::getResults()) — men en lista med N containrar MED bild blir
 * N+1, och en enkelradssida med bild får tre oplanerade lazy-load-frågor:
 * bilagan, filen och dess derivat. Det senare gäller varje sida under
 * containerns skal, eftersom skalets topprad ritar `cover` — inte bara
 * listorna. Mönstret finns i två former:
 * `->with([...])` i listorna (App\Http\Controllers\ContainerController::index(),
 * App\Http\Controllers\Api\ContainerController::index() och
 * App\Actions\Container\ListContainerSummaries) och `->loadMissing([...])` i
 * enkelradsvägarna (App\Http\Controllers\ContainerController::show/edit() och
 * de övriga kontrollerna som renderar containern).
 *
 * @mixin Container
 */
class ContainerResource extends JsonResource
{
    /**
     * Containerns bild i det format vyerna ritar ur, eller null när ingen är
     * satt — se klassens docblock.
     *
     * **`focus` kom med issue 682.** Det är den punkt på bilden som ska synas
     * när ytan beskär den (`object-position: x% y%`), och den läses ur
     * `cover_focus_x`/`cover_focus_y` på containerraden — samma rad som
     * `cover_attachment_id`, så ingen ny fråga ställs. Formen är
     * `{x, y}` i procent, eller `null` när ingen punkt är vald (NULL betyder
     * mitten). Är `cover` null finns ingen bild och ingen punkt att läsa.
     *
     * Publik och statisk därför att App\Actions\Container\ListContainerSummaries
     * bygger sina kort ur en egen fråga och inte genom den här resursen.
     * Kortet bär samma `cover` som containerlistan, och två formuleringar av
     * samma form hade glidit isär — samma skäl som `Container::
     * scopeAccessibleBy()` bär för urvalet.
     *
     * @return array{ulid: string, variants: list<string>, focus: array{x: int, y: int}|null}|null
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
            // Kolumnerna är satta TILLSAMMANS (CHECK-villkoret
            // container_cover_focus_pair, 2026_10_04_000000), så x är null
            // exakt när ingen punkt är vald.
            'focus' => $container->cover_focus_x === null
                ? null
                : ['x' => $container->cover_focus_x, 'y' => $container->cover_focus_y],
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

<?php

namespace App\Actions\Access;

use App\Exceptions\Api\ApiException;
use App\Models\Container;
use App\Models\ContainerAccess;

/**
 * Ändrar en levande åtkomst: `level` och `expires_at` — se issue 55a
 * § Beslut 8, fjärde utbrytningen.
 *
 * Bryts ut ur App\Http\Controllers\Api\ContainerAccessController::update()
 * med kroppen oförändrad. Webben har samma skrivning, och § Beslut 9:s
 * uppdelning gäller SVARET — JSON kontra en mening — inte VILLKORET: "en
 * död rad ändras inte" är en domäninvariant, och två formuleringar av den i
 * två kontrollrar är precis den sortens andra sanning § Beslut 8 finns till
 * för att förhindra. 55b och issue 57 lägger till fler skrivytor mot samma
 * rader.
 *
 * **Ingen `Gate::authorize()`.** Behörigheten prövas av anroparen, precis
 * som i dag — `ContainerPolicy::manageAccess()` är regel 1 MED
 * `read_only`-kontroll, till skillnad från `revokeAccess()`, för att ändra
 * en åtkomst är att hantera åtkomster ([[Konton och åtkomst]]
 * § Behörighetsregler regel 3 och 4). Att pröva den här hade varit att
 * pröva den två gånger; se issue 54 § Beslut 3, samma uppdelning som
 * App\Actions\Container\CreateContainer gör för containertaket.
 *
 * **Kroppen rör bara de två fälten.** `item_id`, `grantee_type`,
 * `grantee_id` och `kind` är `prohibited` i den delade
 * UpdateContainerAccessRequest och är inte `#[Fillable]` på
 * App\Models\ContainerAccess — att flytta en grant är att avsluta en
 * relation och börja en ny, och historiken ska visa det.
 */
class UpdateContainerAccess
{
    /**
     * Kastar `ApiException` (`container_access.revoked`, 422) när raden är
     * död, och fyller annars `level`/`expires_at` och sparar.
     *
     * Undantaget bubblar upp till anroparen, som formulerar sitt eget svar:
     * `/api` låter det bli `{"error":{"code":…}}` ur `Responsable`, webben
     * fångar det och översätter med App\Support\Frontend\ApiErrorTranslator
     * till en mening i formuläret — samma kod, samma rad, två svar.
     *
     * Att höja nivån på en återkallad eller utgången rad är antingen ett
     * misstag eller en väg runt återkallandet, och båda ska nekas.
     *
     * @param  Container  $container  Containern raden hör till, redan
     *                                upplöst av route-modellbindningen.
     *                                Bärs för att de fyra Actionerna anropas
     *                                likformigt — container och rad först —
     *                                så att skrivytorna i 55b och issue 57
     *                                inte behöver en egen signatur.
     * @param  ContainerAccess  $access  Raden ur route-modellbindningen.
     * @param  array<string, mixed>  $attributes  `level` och/eller
     *                                            `expires_at`, redan
     *                                            validerade av
     *                                            UpdateContainerAccessRequest.
     *                                            Ett `expires_at: null` rensar
     *                                            utgången och är tillåtet på
     *                                            `/api`; webbytan skickar
     *                                            aldrig det (§ Beslut 3 i
     *                                            arkitektsvaret).
     *
     * @throws ApiException
     */
    public function handle(Container $container, ContainerAccess $access, array $attributes): ContainerAccess
    {
        if ($access->revoked_at !== null || ($access->expires_at !== null && $access->expires_at->isPast())) {
            throw ApiException::make('container_access.revoked', ['access' => $access->ulid], 422);
        }

        $access->fill($attributes);
        $access->save();

        return $access;
    }
}

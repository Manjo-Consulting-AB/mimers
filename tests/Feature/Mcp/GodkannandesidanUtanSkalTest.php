<?php

use App\Models\Agent;

/*
 * Godkännandesidan ritas utan appens skal, se
 * `resources/js/pages/Mcp/Authorize.vue`.
 *
 * Felet i produktion 2026-11-21: skalets sidolista och klocka laddar om sina
 * valfria proppar med en GET mot sidans egen URL. På `/oauth/authorize?…` kör
 * Passport då godkännandet igen och byter `authToken` i sessionen, medan
 * formuläret bär kvar den första — knappen gav 403. Det första provet visar
 * mekanismen på serversidan, det andra låser att sidan inte har någon layout
 * som kan starta en sådan omladdning.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

it('nekar formulärets token när sidan har hämtats en gång till', function () {
    [, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient();

    $forsta = mcpSidprops(mcpAuktorisera($anvandare, $klient));
    $andra = mcpSidprops(mcpAuktorisera($anvandare, $klient));

    expect($andra['authToken'])->not->toBe($forsta['authToken']);

    mcpGodkann($anvandare, $forsta, 'new')->assertForbidden();

    expect(Agent::query()->count())->toBe(0);
});

it('ritar godkännandesidan utan layout', function () {
    $kod = (string) file_get_contents(resource_path('js/pages/Mcp/Authorize.vue'));

    expect(str_contains($kod, 'layouts/'))->toBeFalse(
        'Authorize.vue importerar en layout — skalets omladdningar byter Passports authToken',
    )->and(str_contains($kod, 'defineOptions'))->toBeFalse(
        'Authorize.vue sätter en layout via defineOptions',
    );
});

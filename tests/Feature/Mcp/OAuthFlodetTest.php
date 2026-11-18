<?php

use App\Models\Agent;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

/*
 * Issue 400 · OAuth-flödet, se [[ADR-0087 MCP-server för agenter]] Riktning 5
 * och 12.
 *
 * Proven motsvarar "Klart när"-punkterna om PKCE, tokenlivslängd, rotation
 * och guardernas åtskillnad: en Passport-token avvisas på `/api` och en
 * Sanctum-token av guarden `mcp`.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

it('avvisar ett authorization code-flöde utan PKCE', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient();

    $query = http_build_query([
        'client_id' => $klient->getKey(),
        'redirect_uri' => mcpRedirectUri($klient),
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'tillstand',
    ]);

    $svar = somAnvandare($anvandare)->getJson('/oauth/authorize?'.$query);

    $svar->assertStatus(400)->assertJsonPath('error', 'invalid_request');

    expect((string) $svar->json('hint'))->toContain('Code challenge');
});

it('avvisar plain som code challenge-metod', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient();

    $svar = mcpAuktorisera($anvandare, $klient, metod: 'plain');

    $svar->assertRedirectContains(mcpRedirectUri($klient));
    expect($svar->headers->get('Location'))->toContain('error=invalid_request')
        ->toContain('state=tillstand');
});

it('ger en access token på en timme och en refresh token med S256', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient();
    $redirectUri = mcpRedirectUri($klient);

    $props = mcpSidprops(mcpAuktorisera($anvandare, $klient, $redirectUri));
    $kod = mcpKod(mcpGodkann($anvandare, $props, 'new'));

    $data = mcpVaxlaKod($klient, $kod, $redirectUri)->assertOk()->json();

    expect($data['token_type'])->toBe('Bearer')
        ->and($data['expires_in'])->toBeGreaterThanOrEqual(3599)->toBeLessThanOrEqual(3600)
        ->and($data['refresh_token'])->not->toBe('');
});

it('roterar refresh-token vid varje användning', function () {
    [, $anvandare] = mcpKonto('pro');
    $flode = mcpAnslut($anvandare);

    $forsta = $flode['refresh_token'];

    $ny = mcpFornya($flode['client'], $forsta)->assertOk()->json();

    expect($ny['refresh_token'])->not->toBe($forsta)
        ->and($ny['access_token'])->not->toBe($flode['access_token']);

    // Den gamla refresh-token är förbrukad — rotationen är hela skyddet mot
    // att en stulen token används två gånger.
    mcpFornya($flode['client'], $forsta)
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});

it('släpper in en Passport-token på guarden mcp', function () {
    [, $anvandare] = mcpKonto('pro');
    $flode = mcpAnslut($anvandare);

    mcpProvrutt();

    // Sessionen från godkännandet får inte bära autentiseringen — provet ska
    // visa att det är tokenet som släpps in, inte att actingAs() råkar gälla.
    auth()->forgetGuards();

    getJson('/__mcp-prova', ['Authorization' => 'Bearer '.$flode['access_token']])
        ->assertOk()
        ->assertJsonPath('anvandare', $anvandare->id);
});

it('avvisar en Sanctum-token på guarden mcp', function () {
    [, $anvandare] = mcpKonto('pro');
    $sanctum = $anvandare->createToken('api')->plainTextToken;

    mcpProvrutt();
    auth()->forgetGuards();

    getJson('/__mcp-prova', ['Authorization' => 'Bearer '.$sanctum])->assertUnauthorized();
});

it('avvisar en Passport-token på /api', function () {
    [, $anvandare] = mcpKonto('pro');
    $flode = mcpAnslut($anvandare);

    auth()->forgetGuards();

    getJson('/api/containers', ['Authorization' => 'Bearer '.$flode['access_token']])
        ->assertUnauthorized();

    // Motprov: samma användares Sanctum-token når fram, så 401:an ovan beror
    // på tokenet och inte på rutten.
    $sanctum = $anvandare->createToken('api')->plainTextToken;

    getJson('/api/containers', ['Authorization' => 'Bearer '.$sanctum])->assertOk();
});

it('gör en raderad agentrads access- och refresh-token obrukbara', function () {
    [, $anvandare] = mcpKonto('pro');
    $flode = mcpAnslut($anvandare);

    mcpProvrutt();
    auth()->forgetGuards();
    getJson('/__mcp-prova', ['Authorization' => 'Bearer '.$flode['access_token']])->assertOk();

    $flode['agent']->delete();

    auth()->forgetGuards();
    getJson('/__mcp-prova', ['Authorization' => 'Bearer '.$flode['access_token']])->assertUnauthorized();
    mcpFornya($flode['client'], $flode['refresh_token'])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_grant');
});

it('lämnar tokens giltiga när agentraden raderas förbi Eloquent', function () {
    /*
     * Provet dokumenterar vad som gäller, inte vad vi vill: raderingen går
     * förbi Eloquent, precis som en kaskad i databasen (`ON DELETE CASCADE`)
     * gör, så `Agent::deleting` avfyrar aldrig och tokens återkallas inte. Det
     * är skillnaden mot raderingen ovan. En token utan agentrad avvisas först
     * när issue 402 kräver att raden finns vid varje anrop.
     */
    [, $anvandare] = mcpKonto('pro');
    $flode = mcpAnslut($anvandare);

    DB::table('agent')->where('id', $flode['agent']->getKey())->delete();

    expect(Agent::query()->count())->toBe(0);

    mcpProvrutt();
    auth()->forgetGuards();
    getJson('/__mcp-prova', ['Authorization' => 'Bearer '.$flode['access_token']])->assertOk();
});

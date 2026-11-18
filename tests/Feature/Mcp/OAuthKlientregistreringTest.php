<?php

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * Issue 400 · klientregistreringen och MCP-switchen, se [[ADR-0087
 * MCP-server för agenter]] Riktning 5, 17 och 19 och
 * App\Http\Controllers\Mcp\Middleware\EnsureMcpEnabled.
 *
 * Varje "Klart när"-punkt i issuen har ett namngivet prov: switchen svarar
 * 404, och en redirect-URI utanför listan eller över `http` (utom localhost)
 * avvisas.
 */

beforeEach(function (): void {
    mcpSakerstallNycklar();
});

it('svarar 404 för registrering, godkännande och metadata när MCP är av', function () {
    config(['mcp.enabled' => false]);

    postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ])->assertNotFound();

    get('/.well-known/oauth-authorization-server')->assertNotFound();
    get('/.well-known/oauth-protected-resource')->assertNotFound();

    get('/oauth/authorize?client_id=okand&redirect_uri=https%3A%2F%2Fclaude.ai%2Fcallback&response_type=code')
        ->assertNotFound();

    post('/oauth/authorize', [
        'client_id' => 'okand',
        'auth_token' => 'okand',
    ])->assertNotFound();
});

it('registrerar en klient dynamiskt med en redirect-URI på en tillåten domän', function () {
    config(['mcp.enabled' => true]);

    $svar = postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ]);

    $svar->assertCreated()
        ->assertJsonPath('token_endpoint_auth_method', 'none')
        ->assertJsonPath('redirect_uris', ['https://claude.ai/api/mcp/auth_callback'])
        ->assertJsonPath('scope', 'mcp:use');

    expect($svar->json('client_id'))->not->toBe('');
});

it('avvisar en redirect-URI utanför listan', function () {
    config(['mcp.enabled' => true]);

    postJson('/oauth/register', [
        'client_name' => 'Ond klient',
        'redirect_uris' => ['https://evil.example/callback'],
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('avvisar http för en domän som bara finns med https', function () {
    config(['mcp.enabled' => true]);

    postJson('/oauth/register', [
        'client_name' => 'Claude över http',
        'redirect_uris' => ['http://claude.ai/callback'],
    ])->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

it('tillåter http för loopback-adresserna', function (string $redirectUri) {
    config(['mcp.enabled' => true]);

    postJson('/oauth/register', [
        'client_name' => 'Claude Code',
        'redirect_uris' => [$redirectUri],
    ])->assertCreated();
})->with([
    'localhost' => 'http://localhost:33418/callback',
    '127.0.0.1' => 'http://127.0.0.1:33418/callback',
]);

it('annonserar auktoriseringsservern och den skyddade resursen', function () {
    config(['mcp.enabled' => true, 'mcp.authorization_server' => null]);

    getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('response_types_supported', ['code'])
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token'])
        ->assertJsonPath('scopes_supported', ['mcp:use'])
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'));

    getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use'])
        ->assertJsonPath('authorization_servers.0', url('/'));
});

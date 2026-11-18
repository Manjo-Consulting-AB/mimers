<?php

use App\Models\Agent;
use App\Models\Notification;
use App\Models\SecurityLog;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;
use function Pest\Laravel\travel;
use function Pest\Laravel\withoutVite;

/*
 * Issue 400 · Mejlets signerade bortkoppling, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 12 och App\Actions\Agent\DisconnectAgent.
 *
 * Länken finns för att en kapad session ska kunna ansluta angriparens egen
 * klient: `claude.ai` är tillåten för alla. Därför fungerar den utan
 * inloggning, och därför får en ändrad eller utgången länk inte göra
 * någonting.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    // Sidan som kvittot renderas i går genom Vite i drift; i sviten räcker
    // Inertia-svaret.
    withoutVite();
});

/**
 * Bortkopplingslänken ur mejlet, som den står i HTML:en.
 */
function mcpLankUrMejl(string $html): string
{
    preg_match('#https?://[^"\']*agents/disconnect/[A-Za-z0-9]+[^"\']*#', $html, $traff);

    expect($traff[0] ?? null)->toBeString('Mejlet saknar bortkopplingslänk.');

    return html_entity_decode($traff[0]);
}

it('kopplar bort agenten utan inloggning', function () {
    [, $anvandare] = mcpKonto('pro');
    $flode = mcpAnslut($anvandare);

    $html = mcpMejlhtml(
        Notification::query()->where('type', Notification::TYPE_AGENT_CONNECTED)->sole(),
    );

    $lank = mcpLankUrMejl($html);

    auth()->forgetGuards();

    // Länken får inte radera något när den hämtas: skannrar och
    // länkförhandsvisningar gör samma GET. Sidan visar bekräftelsen.
    get($lank)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Mcp/DisconnectConfirm'));

    expect(Agent::query()->count())->toBe(1);

    post($lank)->assertOk();

    expect(Agent::query()->count())->toBe(0)
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_DISCONNECTED)->sole()->meta)
        ->toMatchArray(['agent_ulid' => $flode['agent']->ulid]);

    // Tokens är återkallade med raden.
    mcpProvrutt();
    auth()->forgetGuards();
    getJson('/__mcp-prova', ['Authorization' => 'Bearer '.$flode['access_token']])->assertUnauthorized();
});

it('gör ingenting när signaturen är ändrad', function () {
    [, $anvandare] = mcpKonto('pro');
    mcpAnslut($anvandare);

    $lank = mcpBortkopplingslank(Agent::query()->sole()->ulid);
    $manipulerad = preg_replace('/signature=[^&]+/', 'signature=manipulerad', $lank);

    get($manipulerad)->assertForbidden();

    expect(Agent::query()->count())->toBe(1);
});

it('gör ingenting när länken har gått ut', function () {
    [, $anvandare] = mcpKonto('pro');
    mcpAnslut($anvandare);

    $lank = mcpBortkopplingslank(Agent::query()->sole()->ulid);

    travel(8)->days();

    get($lank)->assertForbidden();

    expect(Agent::query()->count())->toBe(1);
});

it('gör ingenting när länken pekar på en annan agent', function () {
    [$konto, $anvandare] = mcpKonto('pro');

    $flode = mcpAnslut($anvandare, mcpKlient('Claude'));

    // En andra agent, så att en länk för den ena kan prövas mot den andra.
    $andra = mcpAnslut($anvandare, mcpKlient('ChatGPT'));

    $lank = mcpBortkopplingslank($flode['agent']->ulid);

    // Byt ut ULID:n mot den andra agentens — signaturen gäller då inte.
    $manipulerad = str_replace($flode['agent']->ulid, $andra['agent']->ulid, $lank);

    get($manipulerad)->assertForbidden();

    expect(Agent::query()->count())->toBe(2);
});

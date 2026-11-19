<?php

use App\Actions\Agent\NotifyAgentAccessLost;
use App\Actions\Plan\StartDowngrade;
use App\Console\EnforcesDowngrades;
use App\Models\Agent;
use App\Models\AgentAccountGrant;
use App\Models\AgentContainerGrant;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travel;

/*
 * Issue 409 · Agenterna följer planen, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 11 och 14. Hjälparna mcpAgentMedToken(), mcpVerktyg(), mcpFel(),
 * mcpResultat() och mcpEgenContainer() bor i McpEndpointTest.php.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

function nedgSkriv(string $token, Item $item): array
{
    return mcpFel(mcpVerktyg($token, 'items_update', ['item' => $item->ulid, 'name' => 'Nytt namn']));
}

/**
 * Hur många av agentens spår som finns kvar: raden, rättigheterna och
 * tokens som inte är återkallade.
 *
 * @return array{agent: int, konto: int, container: int, tokens: int}
 */
function nedgSpar(Agent $agent): array
{
    return [
        'agent' => Agent::query()->whereKey($agent->id)->count(),
        'konto' => AgentAccountGrant::query()->where('agent_id', $agent->id)->count(),
        'container' => AgentContainerGrant::query()->where('agent_id', $agent->id)->count(),
        'tokens' => DB::table('oauth_access_tokens')
            ->where('user_id', $agent->user_id)
            ->where('revoked', false)
            ->count(),
    ];
}

/**
 * En ägare (Pro) med en container, och en annan användares agent (Pro) som
 * har fått åtkomst dit med ägarens tak `delete`.
 *
 * @return array{0: Agent, 1: string, 2: Container, 3: Subscription, 4: User}
 */
function nedgAnnansContainer(): array
{
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    [$agarkonto, $agare] = mcpKonto('pro');
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $rad = beviljaAccess($container, $anvandare, 'delete', 'member');
    $rad->agent_level = 'delete';
    $rad->save();
    mcpSlaPaI($agent, $container);

    return [$agent, $token, $container, $agarkonto->subscription()->firstOrFail(), $agare];
}

it('avvisar varje anrop med plan.mcp_unavailable efter current_period_end och behåller allt', function () {
    [$agent, $token] = mcpAgentMedToken();
    mcpEgenContainer($agent);
    $prenumeration = Subscription::query()->where('account_id', $agent->account_id)->firstOrFail();
    $prenumeration->forceFill(['current_period_end' => now()->addDays(10)])->save();

    $fore = nedgSpar($agent);

    // Före slutet fungerar agenten som vanligt.
    mcpResultat(mcpVerktyg($token, 'containers_list'));

    travel(11)->days();

    foreach (['containers_list', 'items_search', 'items_create'] as $verktyg) {
        expect(mcpFel(mcpVerktyg($token, $verktyg))['code'])->toBe('plan.mcp_unavailable');
    }

    // Inget återkallades: raden, rättigheterna och tokens står kvar.
    expect(nedgSpar($agent))->toBe($fore);
});

it('låter läsverktygen lyckas och avvisar skrivverktygen vid past_due', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    Subscription::query()->where('account_id', $agent->account_id)->update(['status' => 'past_due']);
    app(StartDowngrade::class)->handle($agent->account, 'payment_failed');

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]));
    mcpResultat(mcpVerktyg($token, 'containers_list'));

    $fel = nedgSkriv($token, $item);

    // Samma kod som API:et ger när policyn nekar skrivningen (issue 409 § 2).
    expect($fel['code'])->toBe('auth.forbidden')
        ->and($item->refresh()->name)->toBe('Motorn');
});

it('låter samma token fungera igen efter en uppgradering, med samma rättigheter', function () {
    [$agent, $token] = mcpAgentMedToken();
    $container = mcpEgenContainer($agent);
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Motorn']);

    $prenumeration = Subscription::query()->where('account_id', $agent->account_id)->firstOrFail();
    $fore = nedgSpar($agent);

    $prenumeration->forceFill(['current_period_end' => now()->subDay()])->save();
    expect(mcpFel(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]))['code'])->toBe('plan.mcp_unavailable');

    $prenumeration->forceFill(['current_period_end' => now()->addYear()])->save();

    expect(mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid])))->not->toBeEmpty();
    mcpResultat(mcpVerktyg($token, 'items_update', ['item' => $item->ulid, 'name' => 'Motorn II']));

    expect($item->refresh()->name)->toBe('Motorn II')
        ->and(nedgSpar($agent))->toBe($fore);
});

it('stänger andras agenter ute från en ägare på Gratis och återställer dem vid uppgradering', function () {
    [$agent, $token, $container, $prenumeration] = nedgAnnansContainer();
    $item = Item::factory()->create(['container_id' => $container->id, 'name' => 'Pumpen']);

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]));

    $prenumeration->forceFill(['current_period_end' => now()->subDay()])->save();

    // Agentens egen plan är Pro, men ägarens är det inte: åtkomsten är borta.
    $svar = mcpVerktyg($token, 'items_get', ['item' => $item->ulid]);
    expect(mcpFel($svar)['code'])->toBe('resource.not_found');
    expect($svar->json('result.isError'))->toBeTrue();

    // `agent_level` och agentens rader står kvar.
    expect(AgentContainerGrant::query()->where('agent_id', $agent->id)->where('container_id', $container->id)->count())
        ->toBeGreaterThan(0);

    $prenumeration->forceFill(['current_period_end' => now()->addYear()])->save();

    mcpResultat(mcpVerktyg($token, 'items_get', ['item' => $item->ulid]));
});

it('ger en agent.access_lost per användare och ägare, inte en per container', function () {
    [$agent, , $container, $prenumeration] = nedgAnnansContainer();
    $andra = Container::factory()->for($container->account, 'account')->create();
    beviljaAccess($andra, $agent->user, 'delete', 'member')->forceFill(['agent_level' => 'delete'])->save();
    mcpSlaPaI($agent, $andra);

    // Ägaren har Pro: ingen notis.
    expect(app(NotifyAgentAccessLost::class)->handle())->toBe(0);

    $prenumeration->forceFill(['current_period_end' => now()->subDay()])->save();

    expect(app(NotifyAgentAccessLost::class)->handle())->toBe(1);
    // Omkörningen skapar ingen ny.
    expect(app(NotifyAgentAccessLost::class)->handle())->toBe(0);
    app(EnforcesDowngrades::class)->handle();

    $notiser = Notification::query()->where('type', Notification::TYPE_AGENT_ACCESS_LOST)->get();

    expect($notiser)->toHaveCount(1)
        ->and($notiser->first()->user_id)->toBe($agent->user_id)
        ->and($notiser->first()->payload['owner'])->toBe($container->account->name);

    // I appen och som mejl, och ingen av dem går att stänga av.
    $kanaler = NotificationDelivery::query()->where('notification_id', $notiser->first()->id)->pluck('channel')->sort()->values()->all();
    expect($kanaler)->toBe([NotificationDelivery::CHANNEL_EMAIL, NotificationDelivery::CHANNEL_IN_APP]);

    $preferenser = app(NotificationPreferences::class);
    expect($preferenser->isMandatoryEmail(Notification::TYPE_AGENT_ACCESS_LOST))->toBeTrue()
        ->and($preferenser->isMandatoryInApp(Notification::TYPE_AGENT_ACCESS_LOST))->toBeTrue();

    expect(mcpMejlhtml($notiser->first()))->toContain($container->account->name);
});

it('ger ingen agent.access_lost för ägarens egna medlemmar eller för en agent utan åtkomst', function () {
    [$agent, , $container, $prenumeration] = nedgAnnansContainer();
    // En användare utan agent i ägarens container får inget.
    [, $utanAgent] = mcpKonto('pro');
    mcpAnslut($utanAgent);
    beviljaAccess($container, $utanAgent, 'delete', 'member');

    $prenumeration->forceFill(['current_period_end' => now()->subDay()])->save();

    app(NotifyAgentAccessLost::class)->handle();

    expect(Notification::query()->where('type', Notification::TYPE_AGENT_ACCESS_LOST)->pluck('user_id')->all())
        ->toBe([$agent->user_id]);
});

it('nämner MCP i varningsmejlet bara för konton med agenter', function () {
    $varna = function (bool $medAgent): string {
        [$ordinarie, $anvandare] = mcpKonto('pro');
        if ($medAgent) {
            mcpAnslut($anvandare);
        }
        [$agarkonto, $agare] = mcpKonto('pro');
        $container = Container::factory()->for($agarkonto, 'account')->create();
        $item = Item::factory()->create(['container_id' => $container->id, 'billed_account_id' => $ordinarie->id, 'created_by_account_id' => $ordinarie->id]);
        expect($item)->not->toBeNull();

        app(StartDowngrade::class)->handle($ordinarie, 'payment_failed');

        $notis = Notification::query()
            ->where('type', Notification::TYPE_CONTENT_MOVING)
            ->where('user_id', $agare->id)
            ->first();

        return $notis === null ? '' : mcpMejlhtml($notis);
    };

    $med = $varna(true);
    $utan = $varna(false);

    expect($med)->toContain('MCP access')
        ->and($utan)->not->toBe('')
        ->and($utan)->not->toContain('MCP');
});

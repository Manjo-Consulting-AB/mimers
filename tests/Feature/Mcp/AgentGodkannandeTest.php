<?php

use App\Models\Account;
use App\Models\Agent;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\SecurityLog;
use App\Models\User;
use App\Support\Notification\NotificationPreferences;
use Inertia\Testing\AssertableInertia;
use Laravel\Passport\Client;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\post;

/*
 * Issue 400 · Godkännandet, tabellen `agent` och spåren, se [[ADR-0087
 * MCP-server för agenter]] Riktning 12 och 19 och [[Konton och åtkomst]]
 * § agent.
 *
 * Proven motsvarar "Klart när"-punkterna: gratiskontot nekas, Pro och Pro+
 * släpps in, den elfte agenten avvisas men ersättning går, ett godkännande
 * ger en rad i `agent`, en rad i säkerhetsloggen och ett mejl som inte går
 * att stänga av, och samma klient återanvänder raden utan nytt mejl.
 */

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

/**
 * En agentrad skapad direkt, för takproven. Den enda vägen in i appen är
 * App\Actions\Agent\ConnectAgent; provet behöver tio rader utan tio
 * godkännanden.
 *
 * @param  array<string, mixed>  $attribut
 */
function mcpAgentrad(User $anvandare, Account $konto, Client $klient, array $attribut = []): Agent
{
    $agent = new Agent;
    $agent->user_id = $anvandare->getKey();
    $agent->account_id = $konto->getKey();
    $agent->oauth_client_id = (string) $klient->getKey();
    $agent->name = $klient->name;

    foreach ($attribut as $nyckel => $varde) {
        $agent->{$nyckel} = $varde;
    }

    $agent->save();

    return $agent;
}

it('visar Pro-kravet för ett gratiskonto och vägrar godkänna', function () {
    [$konto, $anvandare] = mcpKonto('free');
    $klient = mcpKlient();

    $svar = mcpAuktorisera($anvandare, $klient);

    $svar->assertInertia(fn (AssertableInertia $page) => $page->component('Mcp/Authorize'));

    $props = mcpSidprops($svar);

    expect($props['requiresPro'])->toBeTrue()
        ->and($props['reconnect'])->toBeFalse()
        ->and($props['limitReached'])->toBeFalse();

    mcpGodkann($anvandare, $props, 'new')->assertForbidden();

    expect(Agent::query()->count())->toBe(0)
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_CONNECTED)->count())->toBe(0)
        ->and(Notification::query()->where('type', Notification::TYPE_AGENT_CONNECTED)->count())->toBe(0);
});

it('låter ett Pro-konto godkänna och skriver raden, loggen och mejlet', function (string $plan) {
    [$konto, $anvandare] = mcpKonto($plan);
    $klient = mcpKlient('Claude');

    // En avstängd preferensrad får inte stänga av mejlet, och en digest-rad
    // får inte samla det.
    NotificationPreference::create([
        'user_id' => $anvandare->id,
        'type' => Notification::TYPE_AGENT_CONNECTED,
        'channel' => NotificationDelivery::CHANNEL_EMAIL,
        'enabled' => false,
        'digest' => true,
    ]);

    $props = mcpSidprops(mcpAuktorisera($anvandare, $klient));

    expect($props['requiresPro'])->toBeFalse()
        ->and($props['reconnect'])->toBeFalse();

    $svar = mcpGodkann($anvandare, $props, 'new');

    $svar->assertRedirectContains(mcpRedirectUri($klient));
    expect($svar->headers->get('Location'))->toContain('code=');

    $agent = Agent::query()->sole();

    expect($agent->user_id)->toBe($anvandare->id)
        ->and($agent->account_id)->toBe($konto->id)
        ->and($agent->oauth_client_id)->toBe((string) $klient->getKey())
        ->and($agent->name)->toBe('Claude')
        ->and($agent->paused_at)->toBeNull();

    expect(SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_CONNECTED)->sole()->meta)
        ->toMatchArray(['agent_ulid' => $agent->ulid, 'client' => 'Claude']);

    $notis = Notification::query()->where('type', Notification::TYPE_AGENT_CONNECTED)->sole();

    expect($notis->user_id)->toBe($anvandare->id)
        ->and($notis->payload['client'])->toBe('Claude')
        ->and($notis->payload['agent_ulid'])->toBe($agent->ulid);

    $leverans = NotificationDelivery::query()->where('notification_id', $notis->id)->sole();

    expect($leverans->channel)->toBe(NotificationDelivery::CHANNEL_EMAIL)
        ->and($leverans->digest)->toBeFalse();

    $preferenser = app(NotificationPreferences::class);

    expect($preferenser->isEnabled($anvandare, Notification::TYPE_AGENT_CONNECTED, NotificationDelivery::CHANNEL_EMAIL))->toBeTrue()
        ->and($preferenser->digest($anvandare, Notification::TYPE_AGENT_CONNECTED, NotificationDelivery::CHANNEL_EMAIL))->toBeFalse();
})->with([
    'Pro' => 'pro',
    'Pro+' => 'pro_plus',
]);

it('säger vilken klient som anslöts och länkar till bortkopplingen i mejlet', function () {
    [, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient('ChatGPT');

    $props = mcpSidprops(mcpAuktorisera($anvandare, $klient));
    mcpGodkann($anvandare, $props, 'new')->assertRedirect();

    $agent = Agent::query()->sole();
    $html = mcpMejlhtml(Notification::query()->where('type', Notification::TYPE_AGENT_CONNECTED)->sole());

    expect($html)->toContain('ChatGPT')
        ->toContain('/agents/disconnect/'.$agent->ulid)
        ->and($html)->not->toContain('List-Unsubscribe');
});

it('återanvänder raden när samma klient godkänns igen', function () {
    [$konto, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient('Claude');

    mcpAnslut($anvandare, $klient);
    $agent = Agent::query()->sole();

    // Namn och paus är användarens (issue 407) och ska överleva att klienten
    // ansluter på nytt.
    $agent->name = 'Min agent';
    $agent->paused_at = now();
    $agent->save();

    // Utan ett aktivt token finns inget `hasGrantedScopes` som godkänner åt
    // sidan — samma läge som när refresh-token löpt ut.
    foreach ($agent->accessTokens as $token) {
        $token->forceFill(['expires_at' => now()->subMinute()])->save();
    }

    $props = mcpSidprops(mcpAuktorisera($anvandare, $klient));

    expect($props['reconnect'])->toBeTrue()
        ->and($props['limitReached'])->toBeFalse();

    mcpGodkann($anvandare, $props)->assertRedirect();

    $efter = Agent::query()->sole();

    expect(Agent::query()->count())->toBe(1)
        ->and($efter->ulid)->toBe($agent->ulid)
        ->and($efter->name)->toBe('Min agent')
        ->and($efter->paused_at)->not->toBeNull()
        ->and(Notification::query()->where('type', Notification::TYPE_AGENT_CONNECTED)->count())->toBe(1);
});

it('avvisar en elfte agent men låter en befintlig ersättas', function () {
    [$konto, $anvandare] = mcpKonto('pro');

    $befintliga = collect(range(1, 10))->map(
        fn (int $nummer): Agent => mcpAgentrad($anvandare, $konto, mcpKlient('Agent '.$nummer)),
    );

    $ny = mcpKlient('Ny klient');
    $props = mcpSidprops(mcpAuktorisera($anvandare, $ny));

    expect($props['limitReached'])->toBeTrue()
        ->and($props['agentCount'])->toBe(10)
        ->and($props['maxAgents'])->toBe(10)
        ->and($props['agents'])->toHaveCount(10);

    mcpGodkann($anvandare, $props, 'new')->assertForbidden();
    expect(Agent::query()->count())->toBe(10);

    $ersatt = $befintliga->first();

    mcpGodkann($anvandare, $props, 'replace:'.$ersatt->ulid)->assertRedirect();

    expect(Agent::query()->count())->toBe(10)
        ->and($ersatt->fresh()->oauth_client_id)->toBe((string) $ny->getKey())
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_REPLACED)->count())->toBe(1);
});

it('byter klient på raden, behåller namn och paus och återkallar den gamlas tokens', function () {
    [$konto, $anvandare] = mcpKonto('pro');

    $gammal = mcpKlient('Claude');
    $flode = mcpAnslut($anvandare, $gammal);

    // Användaren har bytt namn och pausat agenten (issue 407) — ersättningen
    // ska behålla båda.
    $flode['agent']->name = 'Min agent';
    $flode['agent']->paused_at = now();
    $flode['agent']->save();

    $ny = mcpKlient('Claude igen');
    $props = mcpSidprops(mcpAuktorisera($anvandare, $ny));

    expect($props['agents'][0]['name'])->toBe('Min agent')
        ->and($props['agents'][0]['paused'])->toBeTrue();

    mcpGodkann($anvandare, $props, 'replace:'.$flode['agent']->ulid)->assertRedirect();

    $agent = Agent::query()->sole();

    expect($agent->oauth_client_id)->toBe((string) $ny->getKey())
        ->and($agent->name)->toBe('Min agent')
        ->and($agent->paused_at)->not->toBeNull();

    $logg = SecurityLog::query()->where('action', SecurityLog::ACTION_AGENT_REPLACED)->sole();

    expect($logg->meta)->toMatchArray([
        'agent_ulid' => $agent->ulid,
        'replaced_client_id' => (string) $gammal->getKey(),
    ]);

    // Den gamla klientens access token fungerar inte längre.
    mcpProvrutt();
    auth()->forgetGuards();

    getJson('/__mcp-prova', ['Authorization' => 'Bearer '.$flode['access_token']])->assertUnauthorized();
});

it('skapar ingen rad när ett godkännande avvisas av Passport', function () {
    [, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient();

    mcpAuktorisera($anvandare, $klient);

    // Fel `auth_token`: Passport avvisar, och ingen agent får finnas.
    somAnvandare($anvandare)
        ->post('/oauth/authorize', [
            'client_id' => $klient->getKey(),
            'auth_token' => 'fel',
            'agent_choice' => 'new',
        ])
        ->assertForbidden();

    expect(Agent::query()->count())->toBe(0);
});

it('loggar in en gäst och leder tillbaka till godkännandet', function () {
    [, $anvandare] = mcpKonto('pro');
    $klient = mcpKlient();

    mcpSakerstallNycklar();

    $query = http_build_query([
        'client_id' => $klient->getKey(),
        'redirect_uri' => mcpRedirectUri($klient),
        'response_type' => 'code',
        'scope' => 'mcp:use',
        'state' => 'tillstand',
        'code_challenge' => mcpUtmaning('test-verifierare-0000000000000000000000000000000000000000'),
        'code_challenge_method' => 'S256',
    ]);

    $auktorisering = '/oauth/authorize?'.$query;

    get($auktorisering)->assertRedirect('/login');

    $intended = (string) session('url.intended');

    expect($intended)->toStartWith(url('/oauth/authorize?'))
        ->toContain('client_id='.$klient->getKey())
        ->toContain('code_challenge_method=S256');

    // Inloggningen skickar tillbaka till godkännandet, inte till dashboarden.
    post('/login', [
        'email' => $anvandare->email,
        'password' => 'password',
    ])->assertRedirect($intended);
});

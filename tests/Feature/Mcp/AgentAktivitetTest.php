<?php

use App\Console\AdvancesAccountLifecycle;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\travel;

/*
 * Issue 457 · Agentens arbete är aktivitet, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 3 och [[Planer och kvoter]] § Kontolivscykel. Ett
 * verktygsanrop som agenten fick köra skriver `user.last_active_at`;
 * avvisade anrop och protokollanrop gör det inte.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-23 12:00:00');
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    RateLimiter::clear('x');
});

afterEach(function () {
    Carbon::setTestNow();
});

function agentAktivitetGammal(User $anvandare): void
{
    $anvandare->forceFill(['last_active_at' => now()->subDays(40)])->saveQuietly();
}

function agentAktivitetSkrivningar(Closure $anrop): int
{
    $antal = 0;
    DB::listen(function (QueryExecuted $query) use (&$antal): void {
        if (str_starts_with(strtolower($query->sql), 'update') && str_contains($query->sql, '`user`')) {
            $antal++;
        }
    });
    $anrop();

    return $antal;
}

it('skriver last_active_at vid ett verktygsanrop och inte igen inom ett dygn', function () {
    [, $token, $anvandare] = mcpAgentMedToken();
    agentAktivitetGammal($anvandare);

    mcpVerktyg($token, 'containers_list')->assertOk();
    expect($anvandare->refresh()->last_active_at->equalTo(now()))->toBeTrue();

    travel(2)->hours();
    $skrivningar = agentAktivitetSkrivningar(fn () => mcpVerktyg($token, 'containers_list')->assertOk());

    expect($skrivningar)->toBe(0);
});

it('skriver när kolumnen är äldre än ett dygn efter en dag', function () {
    [, $token, $anvandare] = mcpAgentMedToken();
    mcpVerktyg($token, 'containers_list')->assertOk();

    travel(25)->hours();
    mcpVerktyg($token, 'containers_list')->assertOk();

    expect($anvandare->refresh()->last_active_at->equalTo(now()))->toBeTrue();
});

it('räknar ett verktyg som svarar med ett eget fel', function () {
    [, $token, $anvandare] = mcpAgentMedToken();
    agentAktivitetGammal($anvandare);

    $fel = mcpFel(mcpVerktyg($token, 'items_get', ['item' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']));

    expect($fel['code'])->not->toBe('agent.paused')
        ->and($anvandare->refresh()->last_active_at->equalTo(now()))->toBeTrue();
});

it('skriver inte för en pausad agent', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $agent->forceFill(['paused_at' => now()])->saveQuietly();
    agentAktivitetGammal($anvandare);
    $gammal = $anvandare->refresh()->last_active_at;

    expect(mcpFel(mcpVerktyg($token, 'containers_list'))['code'])->toBe('agent.paused');
    expect($anvandare->refresh()->last_active_at->equalTo($gammal))->toBeTrue();
});

it('skriver inte för ett konto utan plan med MCP', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    Subscription::query()->where('account_id', $agent->account_id)->delete();
    agentAktivitetGammal($anvandare);
    $gammal = $anvandare->refresh()->last_active_at;

    expect(mcpFel(mcpVerktyg($token, 'containers_list'))['code'])->toBe('plan.mcp_unavailable');
    expect($anvandare->refresh()->last_active_at->equalTo($gammal))->toBeTrue();
});

it('skriver inte när rate limit stoppar anropet', function () {
    [, $token, $anvandare] = mcpAgentMedToken();
    config(['mcp.rate_limits.read.agent' => [[1, 60]]]);

    mcpVerktyg($token, 'containers_list')->assertOk();
    agentAktivitetGammal($anvandare);
    $gammal = $anvandare->refresh()->last_active_at;

    expect(mcpFel(mcpVerktyg($token, 'containers_list'))['code'])->toBe('agent.rate_limited');
    expect($anvandare->refresh()->last_active_at->equalTo($gammal))->toBeTrue();
});

it('skriver inte när raderingstaket stoppar anropet', function () {
    [, $token, $anvandare] = mcpAgentMedToken();
    config(['mcp.rate_limits.delete.agent' => [[0, 3600]]]);
    agentAktivitetGammal($anvandare);
    $gammal = $anvandare->refresh()->last_active_at;

    expect(mcpFel(mcpVerktyg($token, 'items_delete', ['item' => '01JZZZZZZZZZZZZZZZZZZZZZZZ']))['code'])
        ->toBe('agent.delete_limit');
    expect($anvandare->refresh()->last_active_at->equalTo($gammal))->toBeTrue();
});

it('räknar inte initialize och tools/list', function () {
    [, $token, $anvandare] = mcpAgentMedToken();
    agentAktivitetGammal($anvandare);
    $gammal = $anvandare->refresh()->last_active_at;

    mcpAnropa($token, 'initialize', [
        'protocolVersion' => '2025-06-18',
        'capabilities' => (object) [],
        'clientInfo' => ['name' => 't', 'version' => '1'],
    ])->assertOk();
    mcpAnropa($token, 'tools/list')->assertOk();

    expect($anvandare->refresh()->last_active_at->equalTo($gammal))->toBeTrue();
});

it('räknar inte ett anrop utan giltig token', function () {
    [, , $anvandare] = mcpAgentMedToken();
    agentAktivitetGammal($anvandare);
    $gammal = $anvandare->refresh()->last_active_at;

    mcpVerktyg('inte-en-token', 'containers_list')->assertUnauthorized();

    expect($anvandare->refresh()->last_active_at->equalTo($gammal))->toBeTrue();
});

it('öppnar ett konto stängt för inaktivitet när agenten arbetat', function () {
    [$agent, $token, $anvandare] = mcpAgentMedToken();
    $konto = $agent->account;
    agentAktivitetGammal($anvandare);
    $konto->forceFill(['status' => 'closed', 'read_only_reason' => 'inactivity'])->saveQuietly();

    mcpVerktyg($token, 'containers_list')->assertOk();
    (new AdvancesAccountLifecycle)->handle();

    $konto->refresh();
    expect($konto->status)->toBe('active')->and($konto->read_only_reason)->toBeNull();
});

<?php

use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;

/*
 * Issue 410 · `testkonto:skapa --mcp`, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 17. Kommandot ska ge testarna på staging två konton: ett med
 * containrarna och ett som fått en av dem delad, så att delade containrar och
 * ägarens tak (Riktning 6) kan provas utan handpåläggning.
 *
 * Utan `--mcp` ska kommandot bete sig precis som i dag; den sidan låses av
 * tests/Feature/Drift/TestkontoTest.php, och ett prov här ser att flaggan är
 * det enda som lägger till något.
 */

beforeEach(function (): void {
    Storage::fake('files');
    config(['konton.internal_pro_emails' => ['forst@example.test']]);
});

/**
 * Kör kommandot med lösenorden som svar på frågorna — två frågor per konto.
 */
function mcpTestkontoKör(string $email, bool $mcp = true, string $lösenord = 'ett-langt-losenord-123', ?string $mcpLösenord = null): PendingCommand
{
    [$local, $domän] = explode('@', $email, 2);
    $mcpEmail = $local.'+mcp@'.$domän;

    $kommando = artisan('testkonto:skapa', ['email' => $email] + ($mcp ? ['--mcp' => true] : []))
        ->expectsQuestion('Lösenord', $lösenord)
        ->expectsQuestion('Lösenord igen', $lösenord);

    if ($mcp) {
        $kommando = $kommando
            ->expectsQuestion("Lösenord för {$mcpEmail}", $mcpLösenord ?? $lösenord)
            ->expectsQuestion("Lösenord för {$mcpEmail} igen", $mcpLösenord ?? $lösenord);
    }

    return $kommando;
}

it('skapar en andra Pro-användare och delar den första containern med agenttak read', function () {
    mcpTestkontoKör('forst@example.test')
        ->expectsOutputToContain('Testkontot forst@example.test skapades.')
        ->expectsOutputToContain('Testkontot forst+mcp@example.test skapades.')
        ->expectsOutputToContain('Delat: Hemmet med forst+mcp@example.test (level write, agent_level read)')
        ->assertExitCode(0)
        ->run();

    $agare = User::query()->where('email', 'forst@example.test')->firstOrFail();
    $medlem = User::query()->where('email', 'forst+mcp@example.test')->firstOrFail();

    expect($medlem->hasVerifiedEmail())->toBeTrue();

    // Båda är Pro, även om bara den första adressen står i internal_pro_emails.
    foreach ([$agare, $medlem] as $anvandare) {
        $konto = $anvandare->accounts()->wherePivot('role', 'owner')->firstOrFail();
        expect($konto->currentPlan()->code)->toBe('pro')
            ->and($konto->subscription->external_ref)->toBe('internal');
    }

    // Den andra användaren har inga egna containers; bara åtkomsten hon fick.
    $medlemskonto = $medlem->accounts()->wherePivot('role', 'owner')->firstOrFail();
    expect(Container::query()->where('account_id', $medlemskonto->id)->count())->toBe(0);

    $agarkonto = $agare->accounts()->wherePivot('role', 'owner')->firstOrFail();
    $container = Container::query()
        ->where('account_id', $agarkonto->id)
        ->orderBy('id')
        ->firstOrFail();

    expect($container->name)->toBe('Hemmet');

    $åtkomster = ContainerAccess::query()->get();
    expect($åtkomster)->toHaveCount(1);

    $åtkomst = $åtkomster->first();
    expect($åtkomst->container_id)->toBe($container->id)
        ->and($åtkomst->grantee_type)->toBe('user')
        ->and($åtkomst->grantee_id)->toBe($medlem->id)
        ->and($åtkomst->level)->toBe('write')
        ->and($åtkomst->agent_level)->toBe('read')
        ->and($åtkomst->kind)->toBe('member')
        ->and($åtkomst->granted_by_user_id)->toBe($agare->id)
        ->and($åtkomst->item_id)->toBeNull();
});

it('skapar bara ett konto utan --mcp', function () {
    mcpTestkontoKör('forst@example.test', mcp: false)
        ->expectsOutputToContain('Testkontot forst@example.test skapades.')
        ->assertExitCode(0)
        ->run();

    expect(User::query()->count())->toBe(1)
        ->and(User::query()->where('email', 'forst@example.test')->exists())->toBeTrue()
        ->and(ContainerAccess::query()->count())->toBe(0);
});

it('avvisar ett för kort lösenord för den andra utan att lämna rader', function () {
    $före = [User::query()->count(), Container::withTrashed()->count(), ContainerAccess::query()->count()];

    mcpTestkontoKör('forst@example.test', mcpLösenord: 'kort')
        ->expectsOutputToContain('Lösenordet uppfyller inte appens lösenordsregel.')
        ->assertExitCode(1)
        ->run();

    expect([User::query()->count(), Container::withTrashed()->count(), ContainerAccess::query()->count()])->toBe($före);
});

it('avvisar en befintlig plusadress innan det första kontot skrivs', function () {
    User::factory()->create(['email' => 'forst+mcp@example.test']);

    artisan('testkonto:skapa', ['email' => 'forst@example.test', '--mcp' => true])
        ->expectsOutputToContain('Det finns redan ett konto med adressen forst+mcp@example.test.')
        ->assertExitCode(1)
        ->run();

    expect(User::query()->where('email', 'forst@example.test')->exists())->toBeFalse()
        ->and(Container::withTrashed()->count())->toBe(0)
        ->and(ContainerAccess::query()->count())->toBe(0);
});

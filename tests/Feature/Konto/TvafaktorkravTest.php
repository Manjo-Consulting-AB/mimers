<?php

use App\Actions\Access\ResolveItemScope;
use App\Actions\Agent\ResolveAgentAccess;
use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\SearchAccessibleItems;
use App\Console\GeneratesTaskNotifications;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\CalendarFeed;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Notification;
use App\Models\User;
use App\Policies\ContainerPolicy;
use App\Support\Agent\ItemAudience;
use App\Support\Mcp\ContainerGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

/*
 * Issue 1520 · Tvåfaktor som krav för delade containrar, spärren i åtkomsten.
 * Se ADR-0092 (valvissue #1524). En grant ger ingenting i en container som
 * kräver tvåfaktor — egen flagga eller ägarkontots — när mottagaren saknar
 * bekräftad tvåfaktor. Ägarkontots medlemmar och inboxen berörs inte.
 *
 * Hjälparna har prefixet `tvafaktorkrav`.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-09-02 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ägarkonto med en container och ett item, plus en mottagare UTANFÖR
 * kontot med en container-bred grant. Ingen av dem har tvåfaktor än.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item, 4: User, 5: array<string, string>}
 */
function tvafaktorkravBas(bool $kravPaContainer = true): array
{
    [$konto, $agare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create([
        'name' => 'Vindil',
        'require_two_factor' => $kravPaContainer,
    ]);
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $agare->id,
        'created_by_account_id' => $konto->id,
    ]);

    [, $mottagare, $huvuden] = kontoMedMedlem();
    beviljaAccess($container, $mottagare, 'write', 'guest');

    return [$konto, $agare, $container, $item, $mottagare, $huvuden];
}

function tvafaktorkravSla(User $user, bool $pa): void
{
    $user->forceFill(['totp_confirmed_at' => $pa ? now() : null])->save();
    app(ResolveItemScope::class)->flush();
    // Tjänsterna som bär en egen referens till omfångsupplösaren byggs om,
    // som de görs för varje begäran i drift.
    app()->forgetInstance(ContainerGate::class);
    app()->forgetInstance(ResolveAgentAccess::class);
    // Nästa begäran läser användaren på nytt, som i drift.
    auth()->forgetGuards();
}

it('en mottagare utan tvåfaktor får 403 på containern och itemet och når inga items', function () {
    [, , $container, $item, $mottagare, $huvuden] = tvafaktorkravBas();

    getJson("/api/containers/{$container->ulid}", $huvuden)->assertForbidden();
    getJson("/api/containers/{$container->ulid}/items", $huvuden)->assertForbidden();
    expect(app(ResolveItemScope::class)->handle($mottagare, $container)->levelFor($item->id) !== null)->toBeFalse();
    expect(app(ContainerPolicy::class)->view($mottagare, $container))->toBeFalse();
});

it('samma mottagare med bekräftad tvåfaktor når containern som förut', function () {
    [, , $container, $item, $mottagare, $huvuden] = tvafaktorkravBas();
    tvafaktorkravSla($mottagare, true);

    getJson("/api/containers/{$container->ulid}", $huvuden)->assertOk();
    expect(app(ResolveItemScope::class)->handle($mottagare, $container)->levelFor($item->id) !== null)->toBeTrue();
});

it('ägarkontots medlem påverkas aldrig, även utan tvåfaktor', function () {
    [, $agare, $container, $item] = tvafaktorkravBas();

    expect($agare->hasConfirmedTwoFactor())->toBeFalse();
    expect(app(ContainerPolicy::class)->view($agare, $container))->toBeTrue();
    expect(app(ResolveItemScope::class)->handle($agare, $container)->levelFor($item->id) !== null)->toBeTrue();
    expect(Container::query()->accessibleBy($agare, $agare->accounts->pluck('id')->all())->whereKey($container->id)->exists())->toBeTrue();
});

it('kravet på ägarkontot spärrar lika mycket som kravet på containern', function () {
    [$konto, , $container, $item, $mottagare] = tvafaktorkravBas(false);

    expect(app(ResolveItemScope::class)->handle($mottagare, $container)->levelFor($item->id) !== null)->toBeTrue();

    $konto->forceFill(['require_two_factor' => true])->save();
    app(ResolveItemScope::class)->flush();

    expect($container->fresh()->requiresTwoFactor())->toBeTrue();
    expect(app(ResolveItemScope::class)->handle($mottagare, $container)->levelFor($item->id) !== null)->toBeFalse();
    expect(app(ContainerPolicy::class)->view($mottagare, $container->fresh()))->toBeFalse();
});

it('en itemgrant ger heller ingenting i en låst container', function () {
    [, , $container, $item] = tvafaktorkravBas();
    [, $gast] = kontoMedMedlem();
    ContainerAccess::factory()->create([
        'container_id' => $container->id, 'item_id' => $item->id,
        'grantee_type' => 'user', 'grantee_id' => $gast->id,
        'level' => 'read', 'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    expect(app(ResolveItemScope::class)->handle($gast, $container)->levelFor($item->id) !== null)->toBeFalse();
    tvafaktorkravSla($gast, true);
    expect(app(ResolveItemScope::class)->handle($gast, $container)->levelFor($item->id) !== null)->toBeTrue();
});

it('en inbox berörs inte av kravet', function () {
    [, $agare] = kontoMedMedlem();
    app(ResolveInbox::class)->handle($agare);
    $inbox = Container::query()->where('inbox_user_id', $agare->id)->firstOrFail();
    $inbox->forceFill(['require_two_factor' => true])->save();

    expect(app(ContainerPolicy::class)->view($agare, $inbox->fresh()))->toBeTrue();
});

it('sökningen lämnar inte ut den låsta containerns items', function () {
    [, , , , $mottagare] = tvafaktorkravBas();

    expect(app(SearchAccessibleItems::class)->handle($mottagare, 'Motorn')->pluck('name')->all())->toBe([]);
    tvafaktorkravSla($mottagare, true);
    expect(app(SearchAccessibleItems::class)->handle($mottagare, 'Motorn')->pluck('name')->all())->toBe(['Motorn']);
});

it('/api/containers listar inte den låsta containern', function () {
    [, , $container, , $mottagare, $huvuden] = tvafaktorkravBas();

    $ulider = fn () => collect(getJson('/api/containers', $huvuden)->assertOk()->json('data'))->pluck('ulid')->all();
    expect($ulider())->not->toContain($container->ulid);

    tvafaktorkravSla($mottagare, true);
    expect($ulider())->toContain($container->ulid);
});

it('/tasks visar inte den låsta containerns uppgifter', function () {
    [, , , $item, $mottagare] = tvafaktorkravBas();
    oppnaForekomst($item, ['title' => 'Byt impeller', 'anchor_date' => '2026-09-02', 'lead_days' => 0]);

    $json = fn () => json_encode(actingAs($mottagare)->get('/tasks')->assertOk()->viewData('page')['props']['groups']);

    expect($json())->not->toContain('Byt impeller');
    tvafaktorkravSla($mottagare, true);
    expect($json())->toContain('Byt impeller');
});

it('/history visar inte den låsta containerns händelser', function () {
    [$konto, , $container, $item, $mottagare] = tvafaktorkravBas();
    AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $konto->id,
        'user_id' => $mottagare->id,
        'item_id' => $item->id,
        'action' => AuditLog::ACTION_ITEM_UPDATED,
        'created_at' => now(),
    ]);

    $antal = fn () => count(collect(actingAs($mottagare)->get('/history')->assertOk()->viewData('page')['props'])
        ->flatten()->filter(fn ($v) => $v === 'Vindil')->all());

    expect($antal())->toBe(0);
    tvafaktorkravSla($mottagare, true);
    expect($antal())->toBeGreaterThan(0);
});

it('ICS-flödet utelämnar den låsta containerns uppgifter', function () {
    [, , $container, $item, $mottagare] = tvafaktorkravBas();
    oppnaForekomst($item, ['title' => 'Byt impeller', 'anchor_date' => '2026-09-05', 'lead_days' => 0]);

    $token = Str::random(64);
    CalendarFeed::factory()->create([
        'container_id' => $container->id,
        'user_id' => $mottagare->id,
        'token_hash' => hash('sha256', $token),
    ]);

    $kropp = fn () => get("/kalender/{$token}.ics")->getContent();

    expect($kropp())->not->toContain('SUMMARY:Byt impeller');
    tvafaktorkravSla($mottagare, true);
    expect($kropp())->toContain('SUMMARY:Byt impeller');
});

it('uppgiftsnotiserna går inte till en medlem utan tvåfaktor', function () {
    [, $agare, , $item, $mottagare] = tvafaktorkravBas();
    oppnaForekomst($item, ['title' => 'Byt impeller', 'anchor_date' => '2026-09-02', 'lead_days' => 0]);

    app(GeneratesTaskNotifications::class)->handle();

    expect(Notification::query()->where('user_id', $mottagare->id)->count())->toBe(0);
    expect(Notification::query()->where('user_id', $agare->id)->count())->toBeGreaterThan(0);
});

it('en agent via MCP når inte den låsta containern', function () {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
    [$agent, $token, $anvandare] = mcpAgentMedToken();

    // Ägarkontot måste ha en plan med MCP, annars stänger ContainerGate
    // containern av en annan orsak än tvåfaktorn.
    [$annansKonto] = mcpKonto('pro');
    $container = Container::factory()->for($annansKonto, 'account')->create(['name' => 'Annans', 'require_two_factor' => true]);
    Item::factory()->create(['container_id' => $container->id, 'name' => 'Hemlig motor']);
    beviljaAccess($container, $anvandare, 'read', 'guest');
    mcpSlaPaI($agent, $container);

    $namn = fn () => collect(mcpResultat(mcpVerktyg($token, 'containers_list'))['containers'] ?? [])->pluck('name')->all();

    expect($namn())->not->toContain('Annans');
    tvafaktorkravSla($anvandare, true);
    expect($namn())->toContain('Annans');
});

it('att stänga av tvåfaktorn spärrar vid nästa begäran', function () {
    [, , $container, , $mottagare, $huvuden] = tvafaktorkravBas();
    tvafaktorkravSla($mottagare, true);
    getJson("/api/containers/{$container->ulid}", $huvuden)->assertOk();

    tvafaktorkravSla($mottagare, false);
    auth()->forgetGuards();
    getJson("/api/containers/{$container->ulid}", $huvuden)->assertForbidden();
});

it('accessibleBy med includeLocked släpper igenom den låsta containern', function () {
    [, , $container, , $mottagare] = tvafaktorkravBas();
    $konton = $mottagare->accounts->pluck('id')->all();

    expect(Container::query()->accessibleBy($mottagare, $konton)->whereKey($container->id)->exists())->toBeFalse();
    expect(Container::query()->accessibleBy($mottagare, $konton, includeLocked: true)->whereKey($container->id)->exists())->toBeTrue();
});

it('publiken för ett item räknar inte en mottagare utan tvåfaktor', function () {
    [, $agare, $container, $item, $mottagare] = tvafaktorkravBas();

    expect((new ItemAudience)->forItem($container, $item))->toBe([(int) $agare->id]);
    expect((new ItemAudience)->forContainer($container))->toBe([(int) $agare->id]);

    tvafaktorkravSla($mottagare, true);
    expect((new ItemAudience)->forItem($container, $item))->toEqualCanonicalizing([(int) $agare->id, (int) $mottagare->id]);
});

<?php

use App\Actions\Agent\ResolveAgentAccess;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Policies\ContainerPolicy;
use App\Support\Agent\AgentGroup;
use App\Support\Notification\NotificationPreferences;
use App\Support\Notification\NotificationTarget;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    config(['mcp.enabled' => true]);
    mcpSakerstallNycklar();
});

/*
 * Issue 1529 (M75) · Lämna en container, webben och policyn. API:et prövas i
 * LamnaContainerApiTest.
 */

/**
 * Ett ägarkonto med en container och en mottagare med en container-bred rad.
 *
 * @return array{0: User, 1: Container, 2: User, 3: ContainerAccess}
 */
function lamnaBas(string $niva = 'write'): array
{
    [$konto, $agare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Verkstaden']);
    $mottagare = User::factory()->create(['name' => 'Greta Gäst']);
    $rad = beviljaAccess($container, $mottagare, $niva, 'member');

    return [$agare, $container, $mottagare, $rad];
}

it('en mottagare lämnar från delningssidan och alla hennes giltiga rader återkallas med henne som aktör', function () {
    [, $container, $mottagare, $rad] = lamnaBas();
    $item = Item::factory()->create(['container_id' => $container->id]);
    $itemRad = beviljaAccess($container, $mottagare, 'read', 'guest');
    $itemRad->forceFill(['item_id' => $item->id])->save();
    $annans = beviljaAccess($container, User::factory()->create(), 'read', 'member');

    actingAs($mottagare)
        ->post("/containers/{$container->ulid}/leave")
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('status', 'container-left');

    expect($rad->refresh()->revoked_at)->not->toBeNull()
        ->and($itemRad->refresh()->revoked_at)->not->toBeNull()
        ->and($annans->refresh()->revoked_at)->toBeNull();

    $rader = AuditLog::query()->where('action', AuditLog::ACTION_ACCESS_REVOKED)->get();
    expect($rader)->toHaveCount(2)
        ->and($rader->pluck('user_id')->unique()->all())->toBe([$mottagare->id]);
});

it('knappen ritas bara när can.leave är sant', function () {
    [$agare, $container, $mottagare] = lamnaBas();
    $utomstaende = User::factory()->create();

    $kan = fn (User $u) => actingAs($u)->get("/containers/{$container->ulid}/sharing");

    $kan($mottagare)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('can.leave', true));
    $kan($agare)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('can.leave', false));
    $kan($utomstaende)->assertForbidden();
});

it('ägarkontots medlemmar och en person utan giltig rad får 403', function () {
    [$agare, $container] = lamnaBas();
    $utan = User::factory()->create();
    $utgangen = User::factory()->create();
    beviljaAccess($container, $utgangen, 'read', 'member', revokedAt: now()->subDay());
    $aldrig = User::factory()->create();

    actingAs($agare)->post("/containers/{$container->ulid}/leave")->assertForbidden();
    actingAs($utan)->post("/containers/{$container->ulid}/leave")->assertForbidden();
    actingAs($aldrig)->post("/containers/{$container->ulid}/leave")->assertForbidden();

    $policy = app(ContainerPolicy::class);
    expect($policy->leave($agare, $container))->toBeFalse()
        ->and($policy->leave($utan, $container))->toBeFalse()
        ->and(AuditLog::query()->where('action', AuditLog::ACTION_ACCESS_REVOKED)->count())->toBe(0);
});

it('ett konto som är read_only kan ändå lämna', function () {
    [, $container, $mottagare] = lamnaBas();
    $container->account->forceFill(['status' => 'read_only'])->save();
    Account::query()->whereIn('id', $mottagare->accounts()->pluck('account.id'))->update(['status' => 'read_only']);

    expect(app(ContainerPolicy::class)->leave($mottagare, $container->refresh()))->toBeTrue();

    actingAs($mottagare)->post("/containers/{$container->ulid}/leave")->assertRedirect(route('dashboard'));

    expect(ContainerAccess::query()->where('container_id', $container->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('personens agenter når inte containern efter att hon lämnat', function () {
    [$agent, , $mottagare] = mcpAgentMedToken();
    [$agarkonto] = mcpKonto('pro');
    $container = Container::factory()->for($agarkonto, 'account')->create();
    $rad = beviljaAccess($container, $mottagare, 'delete', 'member');
    $rad->agent_level = 'delete';
    $rad->save();
    mcpSlaPaI($agent, $container);

    $niva = fn () => app(ResolveAgentAccess::class)->handle($agent->refresh(), AgentGroup::Items, $container);
    expect($niva())->toBe('delete');

    actingAs($mottagare)->post("/containers/{$container->ulid}/leave")->assertRedirect();

    app()->forgetInstance(ResolveAgentAccess::class);
    expect($niva())->toBeNull();
});

it('ägaren får access.left i klockan med personens namn och containerns namn, utan mejl och utan växel', function () {
    [$agare, $container, $mottagare] = lamnaBas();

    actingAs($mottagare)->post("/containers/{$container->ulid}/leave")->assertRedirect();

    $notis = Notification::query()->where('type', Notification::TYPE_ACCESS_LEFT)->sole();
    expect($notis->user_id)->toBe($agare->id)
        ->and($notis->payload)->toBe(['container' => 'Verkstaden', 'person' => 'Greta Gäst'])
        ->and(json_encode($notis->payload))->not->toContain($mottagare->email);

    expect(NotificationDelivery::query()->where('notification_id', $notis->id)->pluck('channel')->all())
        ->toBe([NotificationDelivery::CHANNEL_IN_APP]);
    expect(app(NotificationPreferences::class)->types())->not->toContain(Notification::TYPE_ACCESS_LEFT);

    actingAs($agare)->get('/settings/notifications')
        ->assertInertia(fn (AssertableInertia $p) => $p->where('preferences', fn ($rader) => collect($rader)
            ->doesntContain('type', Notification::TYPE_ACCESS_LEFT)));

    expect(app(NotificationTarget::class)->for($notis))
        ->toBe(route('containers.sharing', [$container], false));
});

it('ett andra anrop skriver varken ny loggrad eller ny notis', function () {
    [, $container, $mottagare] = lamnaBas();

    actingAs($mottagare)->post("/containers/{$container->ulid}/leave")->assertRedirect();
    $loggar = AuditLog::query()->count();

    actingAs($mottagare)->post("/containers/{$container->ulid}/leave")->assertRedirect(route('dashboard'));

    expect(AuditLog::query()->count())->toBe($loggar)
        ->and(Notification::query()->where('type', Notification::TYPE_ACCESS_LEFT)->count())->toBe(1);
});

it('en inbox kan inte lämnas', function () {
    [, $container, $mottagare] = lamnaBas();
    $container->forceFill(['inbox_user_id' => User::factory()->create()->id])->save();

    expect(app(ContainerPolicy::class)->leave($mottagare, $container->refresh()))->toBeFalse();
});

it('kopplar upp rutten, texterna och dialogen i vyn', function () {
    $vy = file_get_contents(resource_path('js/pages/Containers/Sharing.vue'));

    expect($vy)->toContain('v-if="can.leave"')
        ->and($vy)->toContain('/containers/${props.container.ulid}/leave')
        ->and(__('ui.sharing.leave.button'))->toBe('Leave container')
        ->and(__('ui.sharing.leave.body', ['container' => 'X']))->toBe('You will lose access to "X". Only the owner can give it back.');
});

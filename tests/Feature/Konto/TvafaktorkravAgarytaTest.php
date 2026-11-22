<?php

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use PragmaRX\Google2FA\Google2FA;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;

/*
 * Issue 1522 · Tvåfaktor som krav för delade containrar, ägarens yta.
 * Se ADR-0092 (valvissue #1524). Själva låsningen i åtkomsten prövas av
 * TvafaktorkravTest (issue 1520); här prövas kryssrutorna, bekräftelsens
 * underlag, märket i delningslistan, historiken och spärren mot att ägaren
 * stänger av sin egen tvåfaktor.
 *
 * Hjälparna har prefixet `agarkrav`.
 */

function agarkravMedTvafaktor(User $user): User
{
    $user->forceFill([
        'totp_secret' => (new Google2FA)->generateSecretKey(),
        'totp_confirmed_at' => now(),
    ])->save();

    return $user;
}

/**
 * @return array{0: Account, 1: User, 2: Container, 3: User, 4: array<string, string>}
 *                                                                                     konto, ägare, container, mottagare utan tvåfaktor, ägarens API-huvuden
 */
function agarkravBas(bool $agareHarTvafaktor = true): array
{
    [$konto, $agare, $huvuden] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Vindil']);
    [, $mottagare] = kontoMedMedlem();
    $mottagare->forceFill(['name' => 'Gunnar Gäst'])->save();
    beviljaAccess($container, $mottagare, 'read', 'guest');

    if ($agareHarTvafaktor) {
        agarkravMedTvafaktor($agare);
    }

    return [$konto, $agare, $container, $mottagare, $huvuden];
}

it('låter ägaren med tvåfaktor slå på och av kravet på kontot och skriver historik', function () {
    [$konto, $agare] = agarkravBas();
    actingAs($agare);

    patch('/settings/security/require-two-factor', ['account' => $konto->ulid, 'require_two_factor' => true])
        ->assertSessionHasNoErrors();

    expect($konto->fresh()->require_two_factor)->toBeTrue();

    patch('/settings/security/require-two-factor', ['account' => $konto->ulid, 'require_two_factor' => false])
        ->assertSessionHasNoErrors();

    expect($konto->fresh()->require_two_factor)->toBeFalse()
        ->and(AuditLog::query()->where('account_id', $konto->id)->pluck('action')->all())
        ->toBe([AuditLog::ACTION_ACCOUNT_TWO_FACTOR_REQUIRED, AuditLog::ACTION_ACCOUNT_TWO_FACTOR_RELEASED]);
});

it('nekar kravet på kontot utan egen tvåfaktor med two_factor.owner_not_enrolled', function () {
    [$konto, $agare] = agarkravBas(false);
    actingAs($agare);

    patch('/settings/security/require-two-factor', ['account' => $konto->ulid, 'require_two_factor' => true])
        ->assertSessionHasErrors(['require_two_factor' => 'Set up two-factor authentication yourself before you require it from others.']);

    expect($konto->fresh()->require_two_factor)->toBeFalse()
        ->and(AuditLog::query()->where('account_id', $konto->id)->count())->toBe(0);
});

it('låter ägaren slå på och av kravet på en container och skriver historik på containern', function () {
    [, $agare, $container] = agarkravBas();
    actingAs($agare);

    patch("/containers/{$container->ulid}/sharing/require-two-factor", ['require_two_factor' => true])
        ->assertSessionHasNoErrors();
    expect($container->fresh()->require_two_factor)->toBeTrue();

    patch("/containers/{$container->ulid}/sharing/require-two-factor", ['require_two_factor' => false])
        ->assertSessionHasNoErrors();
    expect($container->fresh()->require_two_factor)->toBeFalse()
        ->and(AuditLog::query()->where('container_id', $container->id)->pluck('action')->all())
        ->toBe([AuditLog::ACTION_CONTAINER_TWO_FACTOR_REQUIRED, AuditLog::ACTION_CONTAINER_TWO_FACTOR_RELEASED]);
});

it('nekar kravet på containern utan egen tvåfaktor', function () {
    [, $agare, $container] = agarkravBas(false);
    actingAs($agare);

    patch("/containers/{$container->ulid}/sharing/require-two-factor", ['require_two_factor' => true])
        ->assertSessionHasErrors(['require_two_factor' => 'Set up two-factor authentication yourself before you require it from others.']);

    expect($container->fresh()->require_two_factor)->toBeFalse();
});

it('låter inte en mottagare ändra kravet på containern', function () {
    [, , $container, $mottagare] = agarkravBas();
    agarkravMedTvafaktor($mottagare);
    actingAs($mottagare);

    patch("/containers/{$container->ulid}/sharing/require-two-factor", ['require_two_factor' => true])
        ->assertForbidden();

    expect($container->fresh()->require_two_factor)->toBeFalse();
});

it('låser containerns ruta när kontot kräver tvåfaktor', function () {
    [$konto, $agare, $container] = agarkravBas();
    $konto->forceFill(['require_two_factor' => true])->save();
    actingAs($agare);

    get("/containers/{$container->ulid}/sharing")
        ->assertInertia(fn ($sida) => $sida
            ->where('twoFactor.required', false)
            ->where('twoFactor.accountRequired', true)
            ->where('twoFactor.effective', true));
});

it('listar mottagarna utan tvåfaktor på delningssidan och märker deras rader', function () {
    [, $agare, $container, $mottagare] = agarkravBas();
    $container->forceFill(['require_two_factor' => true])->save();
    $access = $container->accesses()->first();
    actingAs($agare);

    get("/containers/{$container->ulid}/sharing")
        ->assertInertia(fn ($sida) => $sida
            ->where('twoFactor.lacking.0.name', 'Gunnar Gäst')
            ->where('twoFactor.lackingGrantees', [$access->ulid]));

    agarkravMedTvafaktor($mottagare);

    get("/containers/{$container->ulid}/sharing")
        ->assertInertia(fn ($sida) => $sida
            ->where('twoFactor.lacking', [])
            ->where('twoFactor.lackingGrantees', []));
});

it('listar på kontonivån mottagarna i alla kontots containrar', function () {
    [$konto, $agare, , $mottagare] = agarkravBas();
    $annan = Container::factory()->for($konto, 'account')->create();
    [, $tredje] = kontoMedMedlem();
    $tredje->forceFill(['name' => 'Tora Tredje'])->save();
    beviljaAccess($annan, $tredje, 'read', 'guest');
    actingAs($agare);

    get('/settings/security')
        ->assertInertia(fn ($sida) => $sida
            ->where('sharing.account', $konto->ulid)
            ->has('sharing.lacking', 2));
});

it('nekar DELETE /totp på webben och i API:et medan ett krav gäller', function () {
    [$konto, $agare, $container, , $huvuden] = agarkravBas();
    $container->forceFill(['require_two_factor' => true])->save();
    actingAs($agare);

    deleteJson('/totp', ['code' => totpKodFör($agare->totp_secret)])
        ->assertJsonValidationErrors(['code']);

    $svar = deleteJson('/api/totp', ['code' => totpKodFör($agare->totp_secret)], $huvuden);

    $svar->assertStatus(422);
    expect($svar->json('error.code'))->toBe('two_factor.required_by_sharing')
        ->and($svar->json('error.data.containers'))->toBe(1)
        ->and($agare->fresh()->totp_confirmed_at)->not->toBeNull();

    $container->forceFill(['require_two_factor' => false])->save();
    $konto->forceFill(['require_two_factor' => true])->save();

    expect(deleteJson('/api/totp', ['code' => totpKodFör($agare->totp_secret)], $huvuden)->json('error.code'))
        ->toBe('two_factor.required_by_sharing');
});

it('låter ägaren stänga av tvåfaktorn när inget krav gäller', function () {
    [, $agare, , , $huvuden] = agarkravBas();

    deleteJson('/api/totp', ['code' => totpKodFör($agare->totp_secret)], $huvuden)->assertNoContent();

    expect($agare->fresh()->totp_confirmed_at)->toBeNull();
});

it('låter en vanlig medlem stänga av sin tvåfaktor trots att kontot kräver tvåfaktor', function () {
    [$konto, , $container] = agarkravBas();
    $konto->forceFill(['require_two_factor' => true])->save();
    $container->forceFill(['require_two_factor' => true])->save();
    $medlem = agarkravMedTvafaktor(User::factory()->create());
    $konto->users()->attach($medlem, ['role' => 'member']);
    $token = $medlem->createToken('api');

    deleteJson('/api/totp', ['code' => totpKodFör($medlem->totp_secret)], ['Authorization' => "Bearer {$token->plainTextToken}"])
        ->assertNoContent();

    expect($medlem->fresh()->totp_confirmed_at)->toBeNull();
});

it('räknar mottagare med bara itemgrant och kontomottagare som saknande', function () {
    [, $agare, $container] = agarkravBas();
    $container->accesses()->delete();

    [, $grantMottagare] = kontoMedMedlem();
    $grantMottagare->forceFill(['name' => 'Ida Itemgrant'])->save();
    $item = Item::factory()->for($container, 'container')->create();
    beviljaAccess($container, $grantMottagare, 'read', 'guest')->forceFill(['item_id' => $item->id])->save();

    [$gastKonto, $kontoMedlem] = kontoMedMedlem();
    $kontoMedlem->forceFill(['name' => 'Kalle Kontomedlem'])->save();
    beviljaAccess($container, $gastKonto, 'read', 'managed');

    $ulider = $container->accesses()->pluck('ulid')->sort()->values()->all();
    actingAs($agare);

    get("/containers/{$container->ulid}/sharing")
        ->assertInertia(fn ($sida) => $sida
            ->has('twoFactor.lacking', 2)
            ->where('twoFactor.lackingGrantees', fn ($lista) => collect($lista)->sort()->values()->all() === $ulider));

    get('/settings/security')->assertInertia(fn ($sida) => $sida->has('sharing.lacking', 2));
});

it('utelämnar ägarkontots egna medlemmar bland mottagarna som saknar tvåfaktor', function () {
    [$konto, $agare, $container] = agarkravBas();
    $container->accesses()->delete();
    $kollega = User::factory()->create();
    $konto->users()->attach($kollega, ['role' => 'member']);
    beviljaAccess($container, $kollega, 'read', 'member');
    beviljaAccess($container, $konto, 'read', 'managed');
    actingAs($agare);

    get("/containers/{$container->ulid}/sharing")
        ->assertInertia(fn ($sida) => $sida->where('twoFactor.lacking', [])->where('twoFactor.lackingGrantees', []));

    get('/settings/security')->assertInertia(fn ($sida) => $sida->where('sharing.lacking', []));
});

it('skriver ingen historikrad när växlingen inte ändrar något', function () {
    [$konto, $agare, $container] = agarkravBas();
    actingAs($agare);

    patch("/containers/{$container->ulid}/sharing/require-two-factor", ['require_two_factor' => false])->assertSessionHasNoErrors();
    patch('/settings/security/require-two-factor', ['account' => $konto->ulid, 'require_two_factor' => false])->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('account_id', $konto->id)->count())->toBe(0);

    patch('/settings/security/require-two-factor', ['account' => $konto->ulid, 'require_two_factor' => true])->assertSessionHasNoErrors();
    patch('/settings/security/require-two-factor', ['account' => $konto->ulid, 'require_two_factor' => true])->assertSessionHasNoErrors();

    expect(AuditLog::query()->where('account_id', $konto->id)->count())->toBe(1);
});

it('skriver ingen historikrad för containerflaggan när kontokravet redan gäller', function () {
    [$konto, $agare, $container] = agarkravBas();
    $konto->forceFill(['require_two_factor' => true])->save();
    actingAs($agare);

    patch("/containers/{$container->ulid}/sharing/require-two-factor", ['require_two_factor' => true])->assertSessionHasNoErrors();

    expect($container->fresh()->require_two_factor)->toBeFalse()
        ->and(AuditLog::query()->where('container_id', $container->id)->count())->toBe(0);
});

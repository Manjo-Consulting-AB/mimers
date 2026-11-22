<?php

// rott-pa-basen: issue 451 (M74), ny testfil — ingen befintlig test rörs.

use App\Models\Container;
use App\Models\OwnershipTransfer;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\postJson;

/**
 * Tvåfaktorkravet vid ägarbyte, se ADR-0092: begäran nekas, accepten nekas
 * och kravet följer med containern.
 */
function tvafaktorUtgangslage(bool $containerKraver, bool $kontoKraver): array
{
    [$säljarkonto, , $säljarHeaders] = kontoMedMedlem();
    $säljarkonto->forceFill(['require_two_factor' => $kontoKraver])->save();
    Subscription::factory()->for($säljarkonto)->for(Plan::where('code', 'pro')->firstOrFail())->create();
    $container = Container::factory()->for($säljarkonto, 'account')->create(['require_two_factor' => $containerKraver]);
    [$köparkonto, $köpare, $köparHeaders] = kontoMedMedlem();

    return [$container, $säljarHeaders, $köparkonto, $köpare, $köparHeaders];
}

it('begäran nekas när containern kräver tvåfaktor och mottagaren saknar den', function () {
    [$container, $headers, $köparkonto] = tvafaktorUtgangslage(true, false);

    $response = postJson("/api/containers/{$container->ulid}/transfers", ['to_account' => $köparkonto->ulid], $headers);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('transfer.recipient_two_factor_required');
    expect(OwnershipTransfer::query()->count())->toBe(0);
});

it('begäran går igenom när mottagaren har bekräftad tvåfaktor', function () {
    [$container, $headers, $köparkonto, $köpare] = tvafaktorUtgangslage(false, true);
    $köpare->forceFill(['totp_confirmed_at' => now()])->save();

    postJson("/api/containers/{$container->ulid}/transfers", ['to_account' => $köparkonto->ulid], $headers)->assertCreated();
});

it('accepten nekas när mottagaren saknar tvåfaktor och ägarbytet står kvar som pending', function () {
    [$container, , $köparkonto, , $köparHeaders] = tvafaktorUtgangslage(true, false);
    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

    $response = postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders);

    $response->assertStatus(422);
    expect($response->json('error.code'))->toBe('transfer.recipient_two_factor_required');
    expect(DB::table('ownership_transfer')->where('id', $överföring->id)->value('status'))->toBe('pending');
    expect($container->fresh()->account_id)->not->toBe($köparkonto->id);
});

it('kravet från det gamla kontots flagga följer med som containerns eget', function () {
    [$container, , $köparkonto, $köpare, $köparHeaders] = tvafaktorUtgangslage(false, true);
    $köpare->forceFill(['totp_confirmed_at' => now()])->save();
    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertSuccessful();

    $container->refresh();
    expect($container->account_id)->toBe($köparkonto->id);
    expect((bool) $container->require_two_factor)->toBeTrue();
});

it('en container utan krav får inget krav av bytet', function () {
    [$container, , $köparkonto, , $köparHeaders] = tvafaktorUtgangslage(false, false);
    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köparkonto->id, 'to_email' => null]);

    postJson("/api/transfers/{$överföring->ulid}/accept", [], $köparHeaders)->assertSuccessful();

    expect((bool) $container->fresh()->require_two_factor)->toBeFalse();
});

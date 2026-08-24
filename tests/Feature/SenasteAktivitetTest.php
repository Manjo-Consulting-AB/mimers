<?php

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;

/*
 * Issue 3 · Konto och användare.
 *
 * `App\Http\Middleware\UpdateLastActiveAt` är registrerad på hela
 * `api`-middlewaregruppen (bootstrap/app.php) och ska uppdatera
 * `user.last_active_at` på vilket autentiserat API-anrop som helst, inte
 * bara inloggning — se [[Konton och åtkomst]] § user och #16.
 *
 * Autentisering byggs i issue 4, inte här. För att bevisa middlewaren
 * räcker ramverkets befintliga guard plus actingAs(), enligt beslutet i
 * #16 — rutten nedan är bara en godtycklig, tom testrutt i api-gruppen.
 * Pests globala hjälpfunktioner används i stället för $this->..., se
 * SkeletonTest.php och ADR-0022.
 */

it('uppdaterar last_active_at vid ett godtyckligt autentiserat API-anrop, inte bara inloggning', function () {
    Route::middleware('api')->get('/_test/godtyckligt-anrop', fn () => response()->noContent());

    $user = User::factory()->create(['last_active_at' => now()->subWeek()]);
    $ursprungligtVärde = Carbon::parse($user->last_active_at);

    actingAs($user);
    getJson('/_test/godtyckligt-anrop')->assertNoContent();

    $uppdaterad = User::query()->findOrFail($user->id);

    expect(Carbon::parse($uppdaterad->last_active_at)->isAfter($ursprungligtVärde))->toBeTrue();
});

it('lämnar last_active_at orört för ett oautentiserat API-anrop', function () {
    Route::middleware('api')->get('/_test/oautentiserat-anrop', fn () => response()->noContent());

    $user = User::factory()->create(['last_active_at' => now()->subWeek()]);
    $ursprungligtVärde = Carbon::parse($user->last_active_at);

    getJson('/_test/oautentiserat-anrop')->assertNoContent();

    $oförändrad = User::query()->findOrFail($user->id);

    expect(Carbon::parse($oförändrad->last_active_at)->equalTo($ursprungligtVärde))->toBeTrue();
});

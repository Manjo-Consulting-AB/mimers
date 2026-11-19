<?php

use App\Models\Account;
use App\Models\Container;
use App\Support\Versioning\VersionLimit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Issue 411 · Inställningarna för versionshistorik, se [[ADR-0088
 * Versionshistorik för items]] Riktning 1.
 *
 * Filen prövar det som gäller oberoende av ytorna: att både ett nytt och ett
 * befintligt konto har 3, och att App\Support\Versioning\VersionLimit::for()
 * räknar fram rätt tak ur kontots och containerns värden. Att värdena går att
 * sätta, och att gränserna hålls, prövas i VersioneringInstallningarTest.
 */

/**
 * Ett konto med ett givet tak och en container i det.
 *
 * @return array{0: Account, 1: Container}
 */
function versioneringKonto(int $accountLimit = 3, ?int $containerLimit = null): array
{
    $account = Account::factory()->create(['versioning_max_versions' => $accountLimit]);

    $container = Container::factory()
        ->for($account, 'account')
        ->create(['versioning_max_versions' => $containerLimit]);

    return [$account, $container];
}

it('ett nytt konto har tre versioner', function () {
    $account = Account::factory()->create();

    // Förvalet sätts av kolumnen, inte av fabriken: instansen läses om.
    expect($account->fresh()->versioning_max_versions)->toBe(3);
});

it('ett befintligt konto har tre versioner', function () {
    // Raden skrivs förbi modellen och utan kolumnen: det är kolumnens
    // default som ska bära förvalet för de konton som fanns före migrationen.
    $ulid = (string) Str::ulid();

    DB::table('account')->insert([
        'ulid' => $ulid,
        'type' => 'personal',
        'name' => 'Gammalt konto',
        'locale' => 'en',
        'timezone' => 'UTC',
        'unit_system' => 'metric',
        'currency' => 'EUR',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $account = Account::query()->where('ulid', $ulid)->firstOrFail();

    expect($account->versioning_max_versions)->toBe(3);
});

it('följer kontot när containern inte har ett eget värde', function () {
    [, $container] = versioneringKonto(5, null);

    expect(VersionLimit::for($container))->toBe(5);
});

it('ger containerns lägre värde när det finns', function () {
    [, $container] = versioneringKonto(5, 2);

    expect(VersionLimit::for($container))->toBe(2);
});

it('ger noll när containern stänger av historiken', function () {
    [, $container] = versioneringKonto(5, 0);

    expect(VersionLimit::for($container))->toBe(0);
});

it('ger noll när kontot stänger av historiken', function () {
    [, $utanEgetVarde] = versioneringKonto(0, null);
    [, $medEgetVarde] = versioneringKonto(0, 2);

    expect(VersionLimit::for($utanEgetVarde))->toBe(0)
        ->and(VersionLimit::for($medEgetVarde))->toBe(0);
});

it('ger kontots värde när kontot sänks under containerns utan att röra raden', function () {
    [$account, $container] = versioneringKonto(5, 3);

    $account->versioning_max_versions = 2;
    $account->save();

    $container->refresh();

    expect(VersionLimit::for($container->fresh()))->toBe(2)
        ->and($container->versioning_max_versions)->toBe(3);
});

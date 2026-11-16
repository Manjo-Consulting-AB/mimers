<?php

use App\Models\Account;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\Lang;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Förbrukningens fördelning mellan filer och text, se ADR-0081 beslut 1,
 * App\Actions\Plan\ReadPlanUsage och resources/js/pages/Settings/Plan.vue.
 */

/**
 * @return array{0: Account, 1: User}
 */
function fordelningKonto(int $filer, int $text): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    UsageCounter::query()->updateOrCreate(
        ['account_id' => $konto->id],
        ['storage_bytes' => $filer, 'metadata_bytes' => $text],
    );

    return [$konto, $anvandare];
}

it('skickar fileBytes och textBytes på /settings/plan, och summan är usedBytes', function () {
    withoutVite();
    [$konto, $anvandare] = fordelningKonto(5000, 300);

    $sida = actingAs($anvandare)->get("/settings/plan?account={$konto->ulid}")->assertOk()->viewData('page');
    $lagring = $sida['props']['usage']['storage'];

    expect($lagring['fileBytes'])->toBe(5000)
        ->and($lagring['textBytes'])->toBe(300)
        ->and($lagring['usedBytes'])->toBe(5300);
});

it('skickar textBytes 0 när kontot inte har någon text', function () {
    withoutVite();
    [$konto, $anvandare] = fordelningKonto(5000, 0);

    $sida = actingAs($anvandare)->get("/settings/plan?account={$konto->ulid}")->assertOk()->viewData('page');
    $lagring = $sida['props']['usage']['storage'];

    expect($lagring['textBytes'])->toBe(0)
        ->and($lagring['fileBytes'])->toBe(5000)
        ->and($lagring['usedBytes'])->toBe(5000);
});

it('skickar textBytes 0 när räknarrad saknas', function () {
    withoutVite();
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $sida = actingAs($anvandare)->get("/settings/plan?account={$konto->ulid}")->assertOk()->viewData('page');

    expect($sida['props']['usage']['storage']['textBytes'])->toBe(0)
        ->and($sida['props']['usage']['storage']['fileBytes'])->toBe(0);
});

it('ritar raden och meningen bara när textBytes är större än noll', function () {
    foreach (['components/StorageBar.vue', 'pages/Settings/Plan.vue', 'pages/Settings/Storage.vue'] as $fil) {
        $kalla = file_get_contents(resource_path("js/{$fil}"));

        expect($kalla)->toMatch('/v-if="[^"]*[tT]extBytes > 0"/', $fil);
    }

    expect(file_get_contents(resource_path('js/components/StorageBar.vue')))->toContain('plan.storage_split');
    expect(file_get_contents(resource_path('js/pages/Settings/Plan.vue')))->toContain('plan.storage_split');
    expect(file_get_contents(resource_path('js/pages/Settings/Storage.vue')))->toContain('storage.text_note');
});

it('har nycklarna för fördelningen i katalogen', function () {
    expect(Lang::get('ui.plan.storage_split', ['files' => '1 KB', 'text' => '2 KB'], 'en'))->toBe('Files 1 KB · Text 2 KB')
        ->and(Lang::get('ui.storage.text_note', [], 'en'))->toContain('Removing files frees the most space.');
});

<?php

use App\Models\Export;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1702 · Kontoexportens avsnitt på profilen. Se resources/js/components/
 * AccountExportSection.vue och App\Http\Controllers\Settings\ProfileController.
 * Sviten kör ingen webbläsare: servern prövas som propar, vyn som källtext.
 */

it('skickar personens egna kontoexporter som prop, nyast först', function () {
    withoutVite();
    [$konto, $user] = kontoMedMedlem();
    [$annatKonto, $annan] = kontoMedMedlem();

    $gammal = Export::factory()->account($konto)->create(['requested_by_user_id' => $user->id, 'created_at' => now()->subHour(), 'status' => Export::STATUS_READY]);
    $ny = Export::factory()->account($konto)->create(['requested_by_user_id' => $user->id]);
    Export::factory()->account($annatKonto)->create(['requested_by_user_id' => $annan->id]);

    actingAs($user)->get('/settings/profile')->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Settings/Profile')
        ->has('accountExports', 2)
        ->where('accountExports.0.ulid', $ny->ulid)
        ->where('accountExports.1.ulid', $gammal->ulid)
        ->where('accountExports.0.kind', 'account')
        ->where('accountExports.0.container', null)
        ->where('accountExports.0.status', 'pending'));
});

it('har ordlistan och avsnittet på profilen', function () {
    $lang = File::get(base_path('lang/en/ui.php'));
    $avsnitt = File::get(resource_path('js/components/AccountExportSection.vue'));
    $profil = File::get(resource_path('js/pages/Settings/Profile.vue'));

    expect($lang)->toContain('All containers you own, with their files, and the data we hold about you. Containers shared with you are listed but not included.')
        ->and($lang)->toContain("'create' => 'Export account'")
        ->and($lang)->toContain('The file is deleted 24 hours after it is ready.')
        ->and($lang)->toContain("'heading' => 'Export your account'")
        ->and($profil)->toContain('<AccountExportSection')
        ->and($avsnitt)->toContain("form.post('/settings/account-export'")
        ->and($avsnitt)->toContain('/exports/${row.ulid}/download')
        ->and($avsnitt)->toContain("only: ['accountExports']");
});

it('stänger knappen medan en rad packas och polllar bara propen', function () {
    $avsnitt = File::get(resource_path('js/components/AccountExportSection.vue'));

    expect($avsnitt)->toContain('form.processing || hasOpenRow')
        ->and($avsnitt)->toContain('onUnmounted');
});

it('ritar avsnittet utan vågrät rullning på 375 px', function () {
    $avsnitt = File::get(resource_path('js/components/AccountExportSection.vue'));

    expect($avsnitt)->toContain('max-w-lg')
        ->and($avsnitt)->toContain('flex-col')
        ->and($avsnitt)->not->toContain('overflow-x')
        ->and($avsnitt)->not->toContain('whitespace-nowrap')
        ->and($avsnitt)->not->toMatch('/\bw-\[\d+px\]/');
});

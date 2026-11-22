<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Issue 381 · [[M59 Profilbilden]] § 381 — profilbilden på inställningssidan
 * och i toppraden.
 *
 * **Källkodsprov, som GenomgangTest.** Ytan är två Vue-filer och en katalog:
 * uppladdningen och borttagningen är klientsidans anrop, avataren är markup,
 * och texten bor i lang/en/ui.php. Det som går att avgöra på serversidan är
 * formen på källkoden — att valet postas till rätt adress med
 * `forceFormData`, att Remove bara ritas när det finns en bild, och att
 * toppraden ritar miniatyren när `has_thumb` är sant med initialerna som
 * reserv.
 *
 * Kommentarerna rensas före varje kontroll, precis som i GenomgangTest:
 * docblocken förklarar `?variant=thumb` och initialerna, och en regel som
 * letar efter dem ska inte kunna nöjas av en mening.
 *
 * Hjälparna har prefixet `profilbildvy` — Pest lägger alla testfiler i samma
 * namnrymd, och tests/Feature/Konto/ProfilbildTest.php äger redan
 * `profilbild*`.
 */

/**
 * Källkoden med kommentarer borta: blockkommentarer, HTML-kommentarer och
 * radkommentarer.
 */
function profilbildvyKod(string $sokvag): string
{
    $kod = File::get(resource_path('js/'.$sokvag));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: *Profile picture* laddar upp, byter och tar bort bilden.
 *
 * Filväljaren postar själv — ingen extra knapp — och `forceFormData` är det
 * som gör filen till en multipart-kropp. Remove är en DELETE mot samma
 * adress och står bakom `v-if="avatar"`: en borttagning utan bild är ett
 * anrop som inte finns.
 */
it('postar profilbilden med forceFormData och visar Remove bara när det finns en bild', function () {
    $kod = profilbildvyKod('pages/Settings/Profile.vue');

    expect($kod)->toContain("router.post('/settings/profile/avatar'")
        ->toContain('forceFormData: true')
        ->toContain("router.delete('/settings/profile/avatar'")
        ->toContain('accept="image/*"');

    preg_match(
        '/<button\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?@click="removeAvatar"(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?>/s',
        $kod,
        $knapp,
    );

    expect($knapp)->toHaveCount(1, 'Remove-knappen saknas i Profile.vue');
    expect($knapp[0])->toContain('v-if="avatar"');
});

/*
 * Klart när: avataren i toppraden visar miniatyren när den finns och
 * initialerna annars, också när bilden inte går att ladda.
 *
 * Sedan issue 382 ritas cirkeln av `UserAvatar`, så skalet importerar och
 * ritar komponenten i stället för att bära en egen `<img>`: varianten,
 * `@error`-fallbacken och initialerna bor där nu (och prövas i
 * ProfilbildUtsnittvyTest). Länkens tillgängliga namn är oförändrat:
 * `aria-label` på länken bär personens namn.
 */
it('ritar profilbilden i toppraden med UserAvatar', function () {
    $kod = profilbildvyKod('layouts/AppLayout.vue');

    expect($kod)->toContain("import UserAvatar from '../components/UserAvatar.vue'")
        ->toContain('<UserAvatar :user="user" :size="44" />')
        ->toContain(':aria-label="user.name"');

    // Cirkeln ritas av komponenten, så skalet bär ingen egen <img> längre.
    expect($kod)->not->toContain('<img');
});

/*
 * Klart när: texterna kommer ur katalogen, som all annan text på ytan.
 *
 * `avatar-updated` och `avatar-removed` sätts av ProfileAvatarController och
 * ritas av FlashMessage; de finns sedan issue 380 och prövas här så att
 * avsnittets katalog är komplett.
 */
it('bär profilbildens texter i katalogen', function () {
    /** @var array<string, string> $avatar */
    $avatar = Lang::get('ui.settings.profile.avatar', [], 'en');

    expect($avatar)->toBeArray()
        ->toHaveKeys(['heading', 'upload', 'remove', 'uploading']);

    foreach ($avatar as $nyckel => $varde) {
        expect(trim((string) $varde))->not->toBe('', "ui.settings.profile.avatar.{$nyckel} är tom");
    }

    expect(Lang::get('ui.flash.avatar-updated', [], 'en'))->toBe('Your profile picture has been saved.')
        ->and(Lang::get('ui.flash.avatar-removed', [], 'en'))->toBe('Your profile picture has been removed.');
});

/*
 * Klart när: profilsidan visar avsnittet *Dashboard image* med uppladdning,
 * borttagning och redigerare.
 *
 * Avsnittet bor i `ProfileHeroSection`, som profilsidan ritar under
 * profilbilden. Uppladdningen postas direkt — ingen extra knapp — och Remove
 * är en DELETE mot samma adress, bakom `v-if="hero"`. Redigeraren är den
 * delade `ImageCropEditor` med `shape="wide"`, eftersom hjälten är bred
 * ([[ADR-0090 Dashboardens hjälte]] § 2 och 4).
 */
it('visar Dashboard image-avsnittet med uppladdning, borttagning och redigerare', function () {
    $profil = profilbildvyKod('pages/Settings/Profile.vue');
    $sektion = profilbildvyKod('components/ProfileHeroSection.vue');

    expect($profil)->toContain("import ProfileHeroSection from '../../components/ProfileHeroSection.vue'")
        ->toContain('<ProfileHeroSection />');

    expect($sektion)->toContain("router.post('/settings/profile/hero'")
        ->toContain("router.delete('/settings/profile/hero'")
        ->toContain("router.patch('/settings/profile/hero/crop'")
        ->toContain('forceFormData: true')
        ->toContain('accept="image/*"')
        ->toContain("t('settings.profile.hero.heading')")
        ->toContain('<ImageCropEditor')
        ->toContain('shape="wide"')
        ->toContain('@save="saveCrop"')
        ->toContain('@cancel="closeEditor"');

    // Remove ritas bara när det finns en bild.
    preg_match(
        '/<button\b(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?@click="removeHero"(?:"[^"]*"|\'[^\']*\'|[^>"\'])*?>/s',
        $sektion,
        $knapp,
    );

    expect($knapp)->toHaveCount(1, 'Remove-knappen saknas i ProfileHeroSection.vue');
    expect($knapp[0])->toContain('v-if="hero"');
});

/*
 * Klart när: hjältebildens texter och flash-koder finns i katalogen.
 *
 * FlashMessage läser `flash.<kod>`; en kod utan nyckel renderas rå.
 */
it('bär hjältebildens texter i katalogen', function () {
    /** @var array<string, string> $hero */
    $hero = Lang::get('ui.settings.profile.hero', [], 'en');

    expect($hero)->toBeArray()
        ->toHaveKeys(['heading', 'upload', 'remove', 'uploading', 'adjust', 'crop_heading']);

    expect($hero['heading'])->toBe('Dashboard image');

    foreach ($hero as $nyckel => $varde) {
        expect(trim((string) $varde))->not->toBe('', "ui.settings.profile.hero.{$nyckel} är tom");
    }

    expect(Lang::get('ui.flash.hero-updated', [], 'en'))->not->toBe('ui.flash.hero-updated')
        ->and(Lang::get('ui.flash.hero-removed', [], 'en'))->not->toBe('ui.flash.hero-removed')
        ->and(Lang::get('ui.flash.hero-cropped', [], 'en'))->not->toBe('ui.flash.hero-cropped');
});

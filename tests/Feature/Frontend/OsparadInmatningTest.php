<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/*
 * Issue 340 · Varningen innan inmatning går förlorad, se
 * [[ADR-0068 Itemets sida och skapandet]] § 10 och [[M45 Itemets sida]] § 340.
 *
 * **Källkodsprov, som grannfilerna.** Att dialogen öppnas i en webbläsare och
 * att webbläsaren ställer sin egen fråga vid en omladdning går inte att avgöra
 * utan en; det står i PR-kroppens handprov. Det som går att avgöra ur källkoden
 * är att kopplingarna finns och bara gäller det de ska: en `GET` till en annan
 * adress, ett ändrat formulär och en bekräftelse som inte frågar en gång till.
 *
 * Skriptet läses med `vueSkript()` och mallen med `vueMall()`: kommentarerna är
 * fulla av just de här orden.
 *
 * Hjälparna har prefixet `osparad` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

function osparadSkript(): string
{
    return vueSkript('js/components/ItemForm.vue');
}

it('avbryter en navigering i router.on before och frågar med UiConfirmDialog', function () {
    $skript = osparadSkript();

    expect($skript)->toContain("router.on('before'");
    expect($skript)->toContain("import UiConfirmDialog from './UiConfirmDialog.vue'");
    expect($skript)->toContain('return false;');
    expect(vueMall('js/components/ItemForm.vue'))->toContain('<UiConfirmDialog');
});

it('frågar bara när formuläret är ändrat', function () {
    expect(osparadSkript())->toMatch('/!\s*form\.isDirty/');
});

it('frågar bara vid en GET till en annan adress', function () {
    $skript = osparadSkript();

    expect($skript)->toContain("visit.method !== 'get'");
    expect($skript)->toContain('visit.url.pathname === window.location.pathname');
    expect($skript)->toContain('visit.prefetch');
});

it('låter Leave göra om besöket och Cancel stanna', function () {
    $skript = osparadSkript();
    $mall = vueMall('js/components/ItemForm.vue');

    expect($skript)->toContain('router.visit(visit.url.href');
    expect($mall)->toContain('@confirm="confirmLeave"');
    expect($mall)->toContain('@cancel="leaveVisit = null"');
    expect($mall)->toContain(':open="leaveVisit !== null"');
});

it('frågar inte en gång till efter bekräftelsen', function () {
    $skript = osparadSkript();

    expect($skript)->toContain('leaveConfirmed = true;');
    expect($skript)->toMatch('/leaveConfirmed\s*\n?\s*\|\|/');
});

it('frågar inte vid ett sparande, Save and add another eller väljarens Create', function () {
    $skript = osparadSkript();

    // De är skrivningar, och bara en GET frågar.
    expect($skript)->toContain("visit.method !== 'get'");
    expect($skript)->toContain('.post(url, { onError: focusFirstError })');
    expect($skript)->toContain('form.patch(');
    expect($skript)->toContain('router.post(`/containers/${props.containerUlid}/${isTag');
});

it('sätter beforeunload bara medan formuläret är ändrat', function () {
    $skript = osparadSkript();

    expect($skript)->toContain("window.addEventListener('beforeunload', warnBeforeUnload)");
    expect($skript)->toContain("window.removeEventListener('beforeunload', warnBeforeUnload)");
    expect($skript)->toMatch('/watch\(\s*\(\)\s*=>\s*form\.isDirty/');
    expect($skript)->toContain('event.preventDefault();');
});

it('tar bort lyssnarna när formuläret avmonteras', function () {
    $skript = osparadSkript();

    expect($skript)->toContain('onBeforeUnmount(');
    expect($skript)->toContain('const removeBeforeListener = router.on(');
    expect($skript)->toMatch('/onBeforeUnmount\(\(\) => \{[^}]*removeBeforeListener\(\);[^}]*removeEventListener\(\'beforeunload\'/s');
});

it('gäller både skapandet och redigeringen', function () {
    $skript = osparadSkript();

    expect($skript)->toContain('function guardNavigation(');

    // Skyddet är komponentens eget och står inte bakom `item === null`.
    expect($skript)->not->toMatch('/guardNavigation[^;]*props\.item/');
    expect(File::get(resource_path('js/pages/Containers/Items/Create.vue')))->toContain('ItemForm');
    expect(File::get(resource_path('js/pages/Containers/Items/Edit.vue')))->toContain('ItemForm');
});

it('har frågans ord i ui.php', function () {
    foreach (['leave_heading', 'leave_body', 'leave_confirm'] as $nyckel) {
        expect(Lang::get("ui.item.form.{$nyckel}", [], 'en'))->not->toBe("ui.item.form.{$nyckel}");
    }

    expect(vueMall('js/components/ItemForm.vue'))->toContain("t('item.form.leave_heading')");
});

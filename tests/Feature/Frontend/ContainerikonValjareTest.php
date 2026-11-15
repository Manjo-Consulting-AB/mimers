<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patch;
use function Pest\Laravel\withoutVite;

/*
 * Issue 315 · Väljaren under containerns inställningar, se
 * [[M38 Containerns ikon]] § 315 och [[ADR-0064 Containerns ikon]] § 3 och 4.
 *
 * **Filen är två prov i ett, som ItemikonValjareTest.** Det första är SVARET:
 * `ContainerResource` bär `icon` och `icon_color` (issue 314),
 * redigeringsvyn får dem, och en PATCH skriver och tömmer båda. Det andra är
 * FORMEN: sektionen *Icon* står bredvid bilden, ritas bara ur `can.update`,
 * visar den valda ikonen eller containerns roll i lådan, och öppnar samma
 * väljare som itemets formulär med fokus tillbaka på knappen.
 *
 * **Det som kräver en webbläsare prövas inte här.** Att arket faktiskt fångar
 * fokus, att fokus landar på knappen efter stängning och att valet syns i
 * sektionen bakom arket står i PR-kroppens handprov. Källkodsproven håller
 * kopplingen som gör det möjligt — `trigger`-proppen och de tre händelserna —
 * och att kontrollern skickar de nuvarande värdena.
 *
 * **`ItemIconPicker.vue` är oförändrad.** Provet läser den för att pinna
 * gränssnittet redigeringsvyn bygger på, och att den fortfarande är generell:
 * en container-gren i väljaren hade varit en andra väljare i samma fil.
 *
 * Hjälparna har prefixet `containerikonvaljare` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs, och grannfilerna äger `container` och
 * `itemikonvaljare`.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function containerikonvaljareKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * En containerbred `read`-mottagare: hon når sidan men får inte uppdatera.
 */
function containerikonvaljareLasare(Container $container, User $agare): User
{
    $lasare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $lasare->id,
        'level' => 'read',
        'kind' => 'member',
        'granted_by_user_id' => $agare->id,
    ]);

    return $lasare;
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar —
 * docblocken är svenska med flit, och en regel som letar efter markup ska
 * inte kunna nöjas av en mening i en kommentar.
 */
function containerikonvaljareKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * ---------------------------------------------------------------------------
 * Svar och skrivning. Klart när-punkterna 2 och 4.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: sektionen visar den valda ikonen i sin färg.
 *
 * Redigeringsvyn får båda ur `ContainerResource` — samma resurs som `/api`:s
 * svar, som bär nycklarna alltid, även som `null` (issue 314).
 */
it('ger redigeringsvyn containerns ikon och färg', function () {
    withoutVite();

    [, $anvandare, $container] = containerikonvaljareKontext();

    $container->update(['icon' => 'sailboat', 'icon_color' => 'teal']);

    $utanIkon = Container::factory()
        ->for($container->account, 'account')
        ->create(['name' => 'Bilen']);

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Edit')
            ->where('container.icon', 'sailboat')
            ->where('container.icon_color', 'teal')
        );

    actingAs($anvandare)
        ->get("/containers/{$utanIkon->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('container.icon', null)
            ->where('container.icon_color', null)
        );
});

/*
 * Klart när: ett val av ikon och färg sparas direkt.
 *
 * Väljaren skickar `choose` till sidan, som gör sin EGEN PATCH med bara
 * `icon` och `icon_color` — inte formulärets. `preserveState` håller
 * osparade fält kvar i vyn, så ett ikonval aldrig nollställer det
 * användaren skrivit men ännu inte sparat (Tonys regel 2026-11-14).
 */
it('sparar en vald ikon och färg direkt', function () {
    withoutVite();

    [, $anvandare, $container] = containerikonvaljareKontext();

    $url = "/containers/{$container->ulid}";

    actingAs($anvandare)->patch($url, [
        'icon' => 'house',
        'icon_color' => 'amber',
    ])->assertRedirect("{$url}/edit");

    $container->refresh();

    expect($container->icon)->toBe('house')
        ->and($container->icon_color)->toBe('amber');

    actingAs($anvandare)
        ->get("{$url}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('container.icon', 'house')
            ->where('container.icon_color', 'amber')
        );

    $vy = containerikonvaljareKod('pages/Containers/Edit.vue');

    // Valet bor inte i formuläret: en ikon får aldrig följa med *Save*.
    // Två rader och inte en kedja — `not->toContain` mitt i en kedja fäller
    // phpstan (Expectation::not finns bara på det första ledet).
    preg_match('/useForm\(\{(.*?)\n\}\)/s', $vy, $formular);

    expect($formular[1] ?? '')->toContain('name: props.container.name');
    expect($formular[1] ?? '')->not->toContain('icon');

    preg_match('/function chooseIcon\(\{ icon, color \}\)\s*\{(.*?)\n\}/s', $vy, $traff);

    expect($traff[1] ?? '')
        ->toContain('router.patch(`/containers/${props.container.ulid}`')
        ->toContain('{ icon, icon_color: color }')
        ->toContain('preserveScroll: true')
        ->toContain('preserveState: true');
});

/*
 * Klart när: ett PATCH med bara ikonen lämnar formulärets fält orörda.
 *
 * `UpdateContainerRequest` säger `sometimes` om varje fält, så en kropp utan
 * `name`, `kind`, `description` och `currency` rör dem inte. Det är det som
 * gör direktsparningen säker: den som skriver i namnfältet och sedan väljer
 * en ikon har kvar sitt namn i rutan — och servern har det orört.
 */
it('lämnar formulärets fält orörda när bara ikonen sparas', function () {
    withoutVite();

    [, $anvandare, $container] = containerikonvaljareKontext();

    $container->update([
        'name' => 'Båten',
        'kind' => 'Segelbåt',
        'description' => 'I sjön sedan 1998.',
        'currency' => 'SEK',
    ]);

    $url = "/containers/{$container->ulid}";

    actingAs($anvandare)->patch($url, [
        'icon' => 'sailboat',
        'icon_color' => 'teal',
    ])->assertRedirect("{$url}/edit");

    $container->refresh();

    expect($container->icon)->toBe('sailboat')
        ->and($container->icon_color)->toBe('teal')
        ->and($container->name)->toBe('Båten')
        ->and($container->kind)->toBe('Segelbåt')
        ->and($container->description)->toBe('I sjön sedan 1998.')
        ->and($container->currency)->toBe('SEK');
});

/*
 * Klart när: *Remove icon* tömmer båda, direkt.
 *
 * Väljaren skickar `remove` till sidan, som gör sin egen PATCH med de två
 * `null` som `UpdateContainerRequest` säger `sometimes|nullable` om — en färg
 * utan ikon är inget val. Redigeringsvyn läser tillbaka dem ur serverns svar.
 */
it('tömmer ikonen och färgen med Remove icon', function () {
    withoutVite();

    [, $anvandare, $container] = containerikonvaljareKontext();

    $container->update(['icon' => 'sailboat', 'icon_color' => 'teal']);

    $url = "/containers/{$container->ulid}";

    actingAs($anvandare)->patch($url, [
        'icon' => null,
        'icon_color' => null,
    ])->assertRedirect("{$url}/edit");

    $container->refresh();

    expect($container->icon)->toBeNull()
        ->and($container->icon_color)->toBeNull();

    actingAs($anvandare)
        ->get("{$url}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('container.icon', null)
            ->where('container.icon_color', null)
        );

    $vy = containerikonvaljareKod('pages/Containers/Edit.vue');

    preg_match('/function removeIcon\(\)\s*\{(.*?)\n\}/s', $vy, $traff);

    expect($traff[1] ?? '')
        ->toContain('router.patch(`/containers/${props.container.ulid}`')
        ->toContain('{ icon: null, icon_color: null }')
        ->toContain('preserveScroll: true')
        ->toContain('preserveState: true');
});

/*
 * ---------------------------------------------------------------------------
 * Sektionen och väljaren. Klart när-punkterna 1, 2, 3 och 6.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: sektionen står bredvid *Cover image* och ritas bara med rätten
 * att uppdatera.
 *
 * Sidan bär redan två grindar (issue 101): formuläret och bilden ritas ur
 * `can.update`, och ikonsektionen följer samma gren — den som bara får läsa
 * ser ingen yta att välja på. Flaggan är presentation; PATCH prövar `update()`
 * på nytt.
 */
it('ritar ikonsektionen bredvid bilden och bara med rätten att uppdatera', function () {
    withoutVite();

    [, $agare, $container] = containerikonvaljareKontext();
    $lasare = containerikonvaljareLasare($container, $agare);

    actingAs($agare)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', true));

    actingAs($lasare)
        ->get("/containers/{$container->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false));

    $vy = containerikonvaljareKod('pages/Containers/Edit.vue');

    // Sektionen, dess rubrik och väljaren står i samma `can.update`-gren.
    expect($vy)->toMatch('/<section v-if="can\.update"[\s\S]*?t\(\'item\.form\.icon\'\)[\s\S]*?<ItemIconPicker/');

    // Och den står före bildsektionen, alltså bredvid *Cover image*.
    expect(strpos($vy, "t('item.form.icon')"))
        ->toBeLessThan((int) strpos($vy, "t('container.cover.heading')"));

    // Bilden har kvar sin egen gren; ikonen ärver samma regel. Sedan issue
    // 1254 finns en tredje gren — *Currencies for costs* — och den ritas av
    // samma `can.update` som de två andra.
    expect(substr_count($vy, '<section v-if="can.update"'))->toBe(3);
});

/*
 * Klart när: sektionen visar den valda ikonen i sin färg, eller lådan.
 *
 * Med ett val ritar `ItemIcon` ikonen i `tile` och palettens färg — ur
 * `props.container`, alltså serverns svar, aldrig ur ett lokalt utkast. Utan
 * val ritas rollen `container` med `UiIcon` i den grå rutan — `ItemIcon` ritar
 * `item` som fallback tills proppen `fallback` kommer i issue 316, och en
 * container utan ikon ska se ut som containerns övriga tomma ytor.
 */
it('visar den valda ikonen eller containerns roll i lådan', function () {
    $vy = containerikonvaljareKod('pages/Containers/Edit.vue');

    expect($vy)->toMatch('/<ItemIcon\s+v-if="container\.icon"\s+:name="container\.icon"\s+:color="container\.icon_color"\s+:size="24"\s+tile\s*\/>/')
        ->toContain('<UiIcon name="container" :size="24" />')
        ->toContain('bg-surface-sunken');

    // Väljaren får samma två värden, så `aria-pressed` visar det som faktiskt
    // är sparat.
    expect($vy)->toContain(':icon="container.icon"')
        ->toContain(':color="container.icon_color"');
});

/*
 * Klart när: ett val sparar direkt och statusraden säger vad som hände.
 *
 * `iconPending` stänger *Choose icon* medan anropet är i luften, och raden
 * under väljaren bär `role="status"` för beskedet och `role="alert"` för ett
 * fel (GenomgangTest § Beslut 6). Texterna kommer ur `lang/`, aldrig ur
 * filen.
 */
it('visar en statusrad efter ett sparat val', function () {
    $vy = containerikonvaljareKod('pages/Containers/Edit.vue');

    expect($vy)
        ->toContain(':disabled="iconPending"')
        ->toContain('role="status"')
        ->toContain('role="alert"')
        ->toContain("t('container.edit.icon_saved')")
        ->toContain("t('container.edit.icon_removed')");

    // Båda funktionerna sätter sin egen status efter serverns svar.
    preg_match('/function chooseIcon\(\{ icon, color \}\)\s*\{(.*?)\n\}/s', $vy, $val);
    preg_match('/function removeIcon\(\)\s*\{(.*?)\n\}/s', $vy, $bort);

    expect($val[1] ?? '')->toContain("iconStatus.value = 'saved'")
        ->and($bort[1] ?? '')->toContain("iconStatus.value = 'removed'");
});

/*
 * Klart när: väljaren öppnas, och fokus går tillbaka till knappen när den
 * stängs.
 *
 * Knappen skickar sitt eget element till `openIconPicker`, som sparar det i
 * `iconTrigger` innan arket öppnas. `UiSheet` lämnar tillbaka fokus till
 * `trigger` när dialogen stängs, oavsett väg — knappen, Esc eller ett tryck
 * utanför. Provet håller båda ändarna av den kopplingen.
 */
it('öppnar väljaren och lämnar tillbaka fokus till knappen', function () {
    $vy = containerikonvaljareKod('pages/Containers/Edit.vue');

    expect($vy)
        ->toContain('<ItemIconPicker')
        ->toContain(':trigger="iconTrigger"')
        ->toContain('@click="openIconPicker($event.currentTarget)"')
        ->toContain('@close="iconPickerOpen = false"');

    preg_match('/function openIconPicker\(element\)\s*\{(.*?)\n\}/s', $vy, $traff);

    expect($traff[1] ?? '')
        ->toContain('iconTrigger.value = element')
        ->toContain('iconPickerOpen.value = true');

    expect(File::get(resource_path('js/components/UiSheet.vue')))
        ->toContain('props.trigger?.focus()');
});

/*
 * Klart när: `ItemIconPicker.vue` är oförändrad.
 *
 * Väljaren ägs av itemets formulär och återanvänds rakt av. Provet pinnar
 * gränssnittet redigeringsvyn bygger på — de fyra propparna, de tre
 * händelserna och `slate`-regeln — och att väljaren fortfarande är generell:
 * en container-gren i den hade varit en andra väljare i samma fil.
 */
it('återanvänder ItemIconPicker utan en container-gren', function () {
    $valjare = containerikonvaljareKod('components/ItemIconPicker.vue');

    expect($valjare)
        ->toContain('open: { type: Boolean, default: false }')
        ->toContain('icon: { type: String, default: null }')
        ->toContain('color: { type: String, default: null }')
        ->toContain('trigger: { type: Object, default: null }')
        ->toContain("const emit = defineEmits(['choose', 'remove', 'close'])")
        ->toContain("emit('choose', { icon: name, color: props.color ?? 'slate' })")
        ->toContain("emit('remove')");

    expect($valjare)->not->toContain('container');
});

/*
 * Texterna återanvänds ur `item.form.*` där orden passar, och de nya
 * statusraderna bor under `container.edit.*` i katalogen. Inga nya nycklar
 * under `item.` behövdes: *Icon* och *Choose icon* säger samma sak på båda
 * ytorna.
 */
it('hämtar sektionens ord ur lang/en/ui.php', function () {
    $vy = containerikonvaljareKod('pages/Containers/Edit.vue');

    expect($vy)
        ->toContain("t('item.form.icon')")
        ->toContain("t('item.form.icon_choose')");

    foreach (['item.form.icon', 'item.form.icon_choose', 'container.edit.icon_saved', 'container.edit.icon_removed'] as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }
});

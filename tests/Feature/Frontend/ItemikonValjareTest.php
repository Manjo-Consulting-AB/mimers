<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patch;
use function Pest\Laravel\withoutVite;

/*
 * Issue 302 · Väljaren under *Edit item*, se [[M35 Itemets ikon och kartans
 * kort]] § 302 och [[ADR-0060 Itemets ikon]] § 5 och 8.
 *
 * **Filen är två prov i ett, som FöräldervaljareTest.** Det första är SVARET:
 * `ItemResource` bär `icon` och `icon_color` (issue 301), redigeringsvyn får
 * dem, och en PATCH skriver och tömmer båda. Det andra är FORMEN: sektionen
 * *Icon* ritas bara när `item` finns, väljaren filtrerar namnlistan på
 * delsträng, ritar 120 ikoner åt gången, och varje ikon- och färgknapp bär
 * sitt namn.
 *
 * **Det som kräver en webbläsare prövas inte här.** Att rutnätet faktiskt
 * hämtar högst 120 ikonfiler, att *Show more* fyller på, att fokusfällan
 * håller i arket och att valet syns i sektionen bakom det står i PR-kroppens
 * handprov. Det som går att avgöra ur källkoden är taket, sökningens form,
 * attributen och att orden kommer ur katalogen — samma sorts källkodsprov som
 * GenomgangTest och FöräldervaljareTest.
 *
 * **`slate`-regeln är klientens.** Servern validerar bara att färgen finns i
 * paletten; det är väljaren som fyller i `slate` när användaren valt en ikon
 * men ingen färg (ADR-0060 § 5). Provet håller därför både källkodsraden och
 * att paret sparas som väljaren skickar det.
 *
 * Hjälparna har prefixet `itemikonvaljare` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs, och `ItemikonTest` äger `itemikon`.
 */

/**
 * Ett konto med en ägare, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function itemikonvaljareKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Ett item i containern med sammanhängande `created_by_*`.
 *
 * @param  array<string, mixed>  $attribut
 */
function itemikonvaljareItem(Container $container, User $skapare, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => 'Motorn',
        'created_by_user_id' => $skapare->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

/**
 * Källkoden med kommentarer borta. Samma tre slag som GenomgangTest rensar —
 * docblocken är svenska med flit, och en regel som letar efter markup ska
 * inte kunna nöjas av en mening i en kommentar.
 */
function itemikonvaljareKod(string $sokvag): string
{
    $kod = File::get(resource_path("js/{$sokvag}"));

    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * ---------------------------------------------------------------------------
 * Svar och skrivning. Klart när-punkterna 2, 5, 6 och 7.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: sektionen visar den nuvarande ikonen och färgen, eller den
 * neutrala.
 *
 * Redigeringsvyn får båda ur `ItemResource` — det är samma resurs som
 * `/api`:s svar, och den bär nycklarna alltid, även som `null` (issue 301).
 * Ett item utan val ger två `null`, vilket är vad `ItemIcon` ritar som den
 * neutrala rutan.
 */
it('ger redigeringsvyn itemets ikon och färg', function () {
    withoutVite();

    [, $anvandare, $container] = itemikonvaljareKontext();

    $medIkon = itemikonvaljareItem($container, $anvandare, [
        'name' => 'Droppen',
        'icon' => 'droplet',
        'icon_color' => 'sky',
    ]);
    $utanIkon = itemikonvaljareItem($container, $anvandare, ['name' => 'Masten']);

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$medIkon->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Edit')
            ->where('item.icon', 'droplet')
            ->where('item.icon_color', 'sky')
        );

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$utanIkon->ulid}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('item.icon', null)
            ->where('item.icon_color', null)
        );
});

/*
 * Klart när: *Edit item* och *New item* visar båda sektionen *Icon*.
 *
 * Skapandeläget har ingen `item`-prop — Create.vue skickar ingen, och
 * ItemForm:s förval är `null` — men ikonen och färgen är ändå fält i
 * formuläret (issue 338), och väljaren ritas ovillkorligen.
 */
it('ritar ikonsektionen i båda lägena', function () {
    withoutVite();

    [, $anvandare, $container] = itemikonvaljareKontext();

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/create")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Create')
            ->missing('item')
        );

    $form = itemikonvaljareKod('components/ItemForm.vue');

    // Sektionen ritas i en gren för varje läge, och väljaren utan villkor.
    expect(substr_count($form, "t('item.form.icon')"))->toBe(2);
    expect($form)
        ->toMatch('/<div v-if="item !== null"[^>]*>\s*<p[^>]*>\{\{ t\(\'item\.form\.icon\'\) \}\}/')
        ->toMatch('/<div v-if="item === null"[^>]*>\s*<p[^>]*>\{\{ t\(\'item\.form\.icon\'\) \}\}/')
        ->toMatch('/<ItemIconPicker\s+:open=/')
        ->toContain('icon: props.item?.icon ?? null')
        ->toContain('icon_color: props.item?.icon_color ?? null')
        ->toContain('<ItemIcon :name="form.icon" :color="form.icon_color" :size="24" tile />')
        ->toContain("t('item.form.icon_choose')");
});

/*
 * Klart när: en vald ikon och färg sparas och syns efter omladdning.
 *
 * PATCH skickar paret som väljaren gör, och redigeringsvyn läser tillbaka
 * samma två värden ur `ItemResource`.
 */
it('sparar en vald ikon och färg och visar dem efter omladdning', function () {
    withoutVite();

    [, $anvandare, $container] = itemikonvaljareKontext();
    $item = itemikonvaljareItem($container, $anvandare);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($anvandare)->patch($url, [
        'name' => 'Motorn',
        'icon' => 'battery-full',
        'icon_color' => 'green',
    ])->assertRedirect($url);

    $item->refresh();

    expect($item->icon)->toBe('battery-full')
        ->and($item->icon_color)->toBe('green');

    actingAs($anvandare)
        ->get("{$url}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('item.icon', 'battery-full')
            ->where('item.icon_color', 'green')
        );
});

/*
 * Klart när: en ikon utan vald färg sparas med `slate`.
 *
 * Regeln bor i väljaren: ett klick på en ikon skickar `props.color ?? 'slate'`,
 * eftersom en ikon utan nyckel hade ritats neutral och sett ut som inget val.
 * Servern tar emot paret som det kommer.
 */
it('sparar slate när en ikon valts utan färg', function () {
    withoutVite();

    [, $anvandare, $container] = itemikonvaljareKontext();
    $item = itemikonvaljareItem($container, $anvandare);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($anvandare)->patch($url, [
        'name' => 'Motorn',
        'icon' => 'droplet',
        'icon_color' => 'slate',
    ])->assertRedirect($url);

    $item->refresh();

    expect($item->icon)->toBe('droplet')
        ->and($item->icon_color)->toBe('slate');

    expect(itemikonvaljareKod('components/ItemIconPicker.vue'))
        ->toContain("color: props.color ?? 'slate'");
});

/*
 * Klart när: *Remove icon* sparar `null` i båda.
 *
 * Väljaren skickar `remove` till formuläret, som tömmer båda fälten i samma
 * funktion — en färg utan ikon är inget val. PATCH skickar de två `null` som
 * `UpdateItemRequest` säger `sometimes|nullable` om, och redigeringsvyn läser
 * tillbaka dem.
 */
it('tömmer ikonen och färgen med Remove icon', function () {
    withoutVite();

    [, $anvandare, $container] = itemikonvaljareKontext();

    $item = itemikonvaljareItem($container, $anvandare, [
        'icon' => 'droplet',
        'icon_color' => 'sky',
    ]);

    $url = "/containers/{$container->ulid}/items/{$item->ulid}";

    actingAs($anvandare)->patch($url, [
        'name' => 'Motorn',
        'icon' => null,
        'icon_color' => null,
    ])->assertRedirect($url);

    $item->refresh();

    expect($item->icon)->toBeNull()
        ->and($item->icon_color)->toBeNull();

    actingAs($anvandare)
        ->get("{$url}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('item.icon', null)
            ->where('item.icon_color', null)
        );

    $form = itemikonvaljareKod('components/ItemForm.vue');

    preg_match('/function removeIcon\(\)\s*\{(.*?)\n\}/s', $form, $traff);

    expect($traff[1] ?? '')
        ->toContain('form.icon = null')
        ->toContain('form.icon_color = null');
});

/*
 * ---------------------------------------------------------------------------
 * Väljarens form. Klart när-punkterna 3, 4 och 8.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: väljaren filtrerar på delsträng i namnet utan hänsyn till
 * skiftläge, och rutnätet ritar högst 120 ikoner tills *Show more* trycks.
 *
 * Namnlistan är Lucides egen och engelsk (ADR-0060 § 8): sökningen översätter
 * den inte, och jämför därför båda sidor i gemener. Taket ligger på 120 per
 * omgång — Lucide har omkring 1 900 filer, och att rita alla hade hämtat alla
 * på en gång.
 */
it('filtrerar namnlistan och ritar 120 ikoner åt gången', function () {
    $kod = itemikonvaljareKod('components/ItemIconPicker.vue');

    expect($kod)
        ->toContain("import { ICON_COLORS, iconNames } from './itemIconPresentation.js'")
        ->toContain('const PAGE_SIZE = 120')
        ->toContain('query.value.trim().toLowerCase()')
        ->toContain('name.toLowerCase().includes(needle)')
        ->toContain('filtered.value.slice(0, shown.value)')
        ->toContain('shown.value += PAGE_SIZE')
        ->toContain('v-for="name in visible"')
        ->toContain('v-for="tone in ICON_COLORS"');
});

/*
 * Klart när: varje ikonknapp har namnet som `aria-label`, varje färgknapp
 * färgens ord, och det valda bär `aria-pressed`.
 *
 * Ikonens `aria-label` är Lucide-namnet ordagrant: det är vad ikonen HETER, och
 * en översättning hade gjort sökningen och etiketten till två olika namn.
 * Färgens etikett slås upp ur katalogen, eftersom paletten är användarens val
 * och orden är produktens. `ItemIcon` är alltid `aria-hidden`, så knappen är
 * den enda ytan som bär namnet.
 */
it('namnger ikon- och färgknapparna och markerar valet', function () {
    $kod = itemikonvaljareKod('components/ItemIconPicker.vue');

    expect($kod)
        ->toContain(':aria-label="name"')
        ->toContain(':aria-label="t(`item.form.icon_colors.${tone}`)"')
        ->toContain(":aria-pressed=\"name === icon ? 'true' : 'false'\"")
        ->toContain(":aria-pressed=\"tone === color ? 'true' : 'false'\"")
        ->toContain(":label=\"t('item.form.icon_search')\"");
});

/*
 * Klart när: alla nya ord kommer ur `lang/en/ui.php`.
 *
 * Nycklarna läses ur källkoden i stället för att räknas upp här, samma form
 * som SprakTest — en mening som läggs till i en komponent och glöms i
 * katalogen faller, och den syns annars som sin egen nyckel i ytan.
 *
 * Färgernas nycklar är dynamiska och fångas därför inte av uttryckslistan:
 * listan läses ur `ICON_COLORS` i modulen, så en ny palettnyckel som saknar
 * ord faller här i stället för att ritas som `item.form.icon_colors.<nyckel>`.
 */
it('hämtar väljarens ord ur lang/en/ui.php', function () {
    $nycklar = [];

    foreach ([
        'components/ItemForm.vue',
        'components/ItemIconPicker.vue',
    ] as $fil) {
        preg_match_all("/(?<![\w$.])t\('([a-z0-9_.]+)'/", File::get(resource_path("js/{$fil}")), $träffar);

        $nycklar = [...$nycklar, ...$träffar[1]];
    }

    expect($nycklar)
        ->toContain('item.form.icon')
        ->toContain('item.form.icon_choose')
        ->toContain('item.form.icon_heading')
        ->toContain('item.form.icon_search')
        ->toContain('item.form.icon_show_more')
        ->toContain('item.form.icon_color')
        ->toContain('item.form.icon_remove')
        ->toContain('item.form.icon_done');

    foreach (array_unique($nycklar) as $nyckel) {
        expect(Lang::get("ui.{$nyckel}", [], 'en'))->not->toBe("ui.{$nyckel}", "ui.{$nyckel} saknas");
    }

    $modul = File::get(resource_path('js/components/itemIconPresentation.js'));

    preg_match('/export const ICON_COLORS = \[(.*?)\]/s', $modul, $traff);
    preg_match_all("/'([a-z]+)'/", $traff[1] ?? '', $fargar);

    expect($fargar[1])->toHaveCount(8);

    foreach ($fargar[1] as $farg) {
        expect(Lang::get("ui.item.form.icon_colors.{$farg}", [], 'en'))
            ->not->toBe("ui.item.form.icon_colors.{$farg}");
    }
});

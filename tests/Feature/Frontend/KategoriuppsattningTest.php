<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Http\Controllers\CategoryController;
use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\User;
use App\Support\Template\MimersTemplates;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 56b · Färdiga kategoriuppsättningar per språk. Se
 * resources/templates/en.php,
 * och [[ADR-0101 Mallarna]]: kortet och rutterna är borta sedan issue 517.
 *
 * Uppsättningarna hängde fram till issue 84 på containerns `kind` och valdes
 * åt användaren. Den nyckeln finns inte längre ([[ADR-0036 Containerns art]]):
 * uppsättningen har ett eget NAMN, och den som vill ha en pekar på den.
 *
 * Den bärande arkitekturen i filen är att SERVERN ALDRIG VET VAD ORDEN
 * BETYDER ([[ADR-0004 Fria taggar och kategorier]] § Konsekvenser). Därför
 * prövas två saker på var sitt plan:
 *
 * - VILKA ord förslaget innehåller: i node, mot samma modul klienten
 *   importerar. En assertion på en PHP-variant av listan hade bevisat noll.
 * - ATT de aldrig blir serverns: inget kategorinamn får förekomma i `lang/`,
 *   `config/` eller en PHP-fil, och ingen sida får skicka orden till
 *   webbläsaren som prop.
 *
 * Ordningen är alltså den omvända mot övriga filer i katalogen: det som
 * bevisas på serversidan är TAKET, TOMHETSKONTROLLEN, BEHÖRIGHETEN och
 * OMDIRIGERINGEN — det som faktiskt är serverkod.
 *
 * Hjälparna har prefixet `uppsattning` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en ägare, och en container med given art. Ägarens locale styr den
 * delade propen `locale` och därmed vilken uppsättning kortet erbjuder — arten
 * styr ingenting (issue 84).
 *
 * @param  array<string, mixed>  $kontoAttribut
 * @return array{0: Account, 1: User, 2: Container}
 */
function uppsattningKontext(
    ?string $anvandarLocale = null,
    string $kind = 'boat',
    array $kontoAttribut = [],
): array {
    $konto = Account::factory()->create($kontoAttribut);
    $anvandare = User::factory()->create(['name' => 'Testperson', 'locale' => $anvandarLocale]);
    $konto->users()->attach($anvandare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create(['kind' => $kind]);

    return [$konto, $anvandare, $container];
}

/**
 * Mimers mallar (resources/templates/en.php) i den form proven läser:
 * rötter med barnens NAMN.
 *
 * @return list<array{name: string, categories: list<array{name: string, children?: list<string>}>}>
 */
function uppsattningSamling(?string $locale = null): array
{
    return array_map(fn ($mall): array => [
        'name' => $mall->name,
        'categories' => array_map(function (array $rot): array {
            if (! isset($rot['children'])) {
                return ['name' => $rot['name']];
            }

            return ['name' => $rot['name'], 'children' => array_column($rot['children'], 'name')];
        }, $mall->content['categories']),
    ], app(MimersTemplates::class)->all($locale));
}

/**
 * Kategorierna i uppsättningen som heter $namn på $locale, eller `null` om
 * namnet inte finns där.
 *
 * @return list<array{name: string, children?: list<string>}>|null
 */
function uppsattningFor(string $locale, string $namn): ?array
{
    foreach (uppsattningSamling($locale) as $uppsattning) {
        if ($uppsattning['name'] === $namn) {
            return $uppsattning['categories'];
        }
    }

    return null;
}

/**
 * Hela datan, så att varje uppsättning kan prövas på en gång.
 *
 * @return array<string, list<array{name: string, categories: list<array<string, mixed>>}>>
 */
function uppsattningAlla(): array
{
    return ['en' => uppsattningSamling('en')];
}

/**
 * Varje namn i en uppsättning, rotnamn och barnnamn, i den ordning de står.
 *
 * @param  list<array{name: string, children?: list<string>}>  $uppsattning
 * @return list<string>
 */
function uppsattningNamn(array $uppsattning): array
{
    $namn = [];

    foreach ($uppsattning as $rot) {
        $namn[] = $rot['name'];

        foreach ($rot['children'] ?? [] as $barn) {
            $namn[] = $barn;
        }
    }

    return $namn;
}

/**
 * Den delade propen `locale` för en inloggad användare, läst ur Inertias
 * rotvy.
 */
function uppsattningLocale(User $anvandare, Container $container): string
{
    /** @var array{props: array{locale: string}} $sida */
    $sida = actingAs($anvandare)
        ->get("/containers/{$container->ulid}/categories")
        ->assertOk()
        ->viewData('page');

    return $sida['props']['locale'];
}

/*
 * Beslut 3: rutterna ligger bakom `auth`. En utloggad besökare skickas till
 * inloggningen och når aldrig en kontrollermetod.
 */

/*
 * Issue 517 · [[ADR-0101 Mallarna]] beslut 4: en mall läggs bara på när en
 * container skapas. Förslagskortet på kategorisidan och dess två rutter är
 * borta; en tom kategorisida visar den vanliga tomma vyn.
 */
it('har inga rutter för förslagskortet och svarar 404 eller 405', function () {
    [, $anvandare, $container] = uppsattningKontext('sv_SE');

    $post = actingAs($anvandare)
        ->post("/containers/{$container->ulid}/categories/preset", ['categories' => [['name' => 'Rigg']]]);
    $delete = actingAs($anvandare)->delete("/containers/{$container->ulid}/categories/preset");

    expect($post->status())->toBeIn([404, 405]);
    expect($delete->status())->toBeIn([404, 405]);

    expect(Category::query()->where('container_id', $container->id)->count())->toBe(0);

    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($rutt) => $rutt->uri())->all();

    expect($uris)->not->toContain('containers/{container}/categories/preset');
});

it('skickar varken förslagsprop eller mallar till en tom kategorisida', function () {
    withoutVite();

    [, $anvandare, $container] = uppsattningKontext('sv_SE');

    actingAs($anvandare)->get("/containers/{$container->ulid}/categories")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Categories')
            ->has('categories', 0)
            ->where('can.manage', true)
            ->missing('presetDismissed')
            ->missing('mimersTemplates')
        );

    // Sidans eget formulär fungerar som förut.
    actingAs($anvandare)
        ->from("/containers/{$container->ulid}/categories")
        ->post("/containers/{$container->ulid}/categories", ['name' => 'Rigg och segel'])
        ->assertSessionHas('status', 'category-created');
});

it('ritar inget förslagskort på kategorisidan och har tagit bort kortet', function () {
    $sida = File::get(resource_path('js/pages/Containers/Categories.vue'));

    expect($sida)->toContain('<CategoryCreateForm')
        ->toContain("t('container.categories.empty')");

    expect(str_contains($sida, 'CategoryPresetCard'))->toBeFalse();
    expect(str_contains($sida, 'presetDismissed'))->toBeFalse();
    expect(File::exists(resource_path('js/components/CategoryPresetCard.vue')))->toBeFalse();
    expect(File::exists(app_path('Http/Requests/Category/StoreCategoryPresetRequest.php')))->toBeFalse();
    expect(method_exists(CategoryController::class, 'storePreset'))->toBeFalse();
    expect(method_exists(CategoryController::class, 'dismissPreset'))->toBeFalse();
});

/*
 * Mimers mallar (resources/templates/en.php) faller tillbaka på engelska för
 * en okänd locale.
 */
it('faller tillbaka på engelska för en okänd locale', function () {
    expect(uppsattningFor('de', 'Boat'))->toBe(uppsattningAlla()['en'][0]['categories']);
    expect(uppsattningFor('de', 'Boat'))->toBe(uppsattningFor('en', 'Boat'));

    // Ett namn som inte finns ger ingenting att visa, inte ett fel.
    expect(uppsattningFor('en', 'rymdskepp'))->toBeNull();
});

/*
 * Beslut 1: varje uppsättning har ett eget namn, sex till tolv rotkategorier
 * och högst två nivåer.
 *
 * Namnet är nytt med issue 84: uppsättningarna hade tidigare containerns `kind`
 * som nyckel, och den nyckeln finns inte längre. Det är namnet väljaren visar,
 * så det får inte vara tomt och inte dubbelt.
 */
it('har namngivna uppsättningar med sex till tolv rötter och två nivåer', function () {
    $alla = uppsattningAlla();

    expect(array_keys($alla))->toBe(['en']);

    foreach ($alla as $locale => $samling) {
        $namn = array_column($samling, 'name');

        expect($namn)->toBe(array_unique($namn), "{$locale} har två uppsättningar med samma namn");

        foreach ($samling as $uppsattning) {
            expect(trim($uppsattning['name']))->not->toBe('');

            $antal = count($uppsattning['categories']);

            expect($antal)->toBeGreaterThanOrEqual(6, "{$locale}/{$uppsattning['name']} har för få rötter");
            expect($antal)->toBeLessThanOrEqual(12, "{$locale}/{$uppsattning['name']} har för många rötter");

            foreach ($uppsattning['categories'] as $rot) {
                expect($rot['name'])->toBeString();
                expect(trim($rot['name']))->not->toBe('');

                foreach ($rot['children'] ?? [] as $barn) {
                    // Två nivåer: ett barn är ett NAMN och inte en nod med
                    // egna barn.
                    expect($barn)->toBeString();
                    expect(trim($barn))->not->toBe('');
                }

                expect(count($rot['children'] ?? []))->toBeLessThanOrEqual(12);
            }
        }
    }
});

/*
 * Hela arkitekturen i issuen: servern tar emot en lista med namn den inte
 * förstår.
 *
 * Inget kategorinamn får förekomma i `lang/`, i `config/` eller i en PHP-fil —
 * som ett citattecken-omslutet värde, för svensk prosa i docblock och
 * kommentarer är något annat än ett inbakat ord. Den dag någon lägger
 * uppsättningarna i en språkfil faller det här testet.
 */
it('har inga kategorinamn i lang/, config/ eller någon PHP-fil', function () {
    $namn = [];

    foreach (uppsattningAlla() as $samling) {
        foreach ($samling as $uppsattning) {
            $namn = [...$namn, ...uppsattningNamn($uppsattning['categories'])];
        }
    }

    expect($namn)->not->toBeEmpty();

    $filer = [
        ...File::allFiles(lang_path()),
        ...File::allFiles(config_path()),
        ...File::allFiles(app_path()),
    ];

    expect($filer)->not->toBeEmpty();

    foreach ($filer as $fil) {
        if ($fil->getExtension() !== 'php') {
            continue;
        }

        $innehåll = $fil->getContents();

        foreach ($namn as $ett) {
            expect($innehåll)->not->toContain("'{$ett}'", sprintf(
                'kategorinamnet "%s" finns i %s',
                $ett,
                $fil->getRelativePathname(),
            ));

            expect($innehåll)->not->toContain("\"{$ett}\"", sprintf(
                'kategorinamnet "%s" finns i %s',
                $ett,
                $fil->getRelativePathname(),
            ));
        }
    }
});

<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Account;
use App\Models\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * Issue 8 · Container. Se App\Http\Controllers\Api\ContainerController,
 * App\Http\Requests\Container\StoreContainerRequest,
 * App\Http\Requests\Container\UpdateContainerRequest,
 * App\Http\Resources\ContainerResource och App\Models\Container.
 *
 * "Klart när" (ContainerCrudTest):
 * - skapar en container åt ett konto användaren är medlem i
 * - listar bara containers från konton användaren är medlem i
 * - uppdaterar namn och kind
 * - account_id kan inte ändras via PATCH
 * - template_source_id kan inte sättas via API:et
 * - radering är mjuk
 * - en mjukraderad container ger 404 resource.not_found
 * - en art utanför den gamla listan tas emot, och `kind` får utelämnas
 *
 * Behörighet (App\Policies\ContainerPolicy) testas separat i
 * tests/Feature/Container/ContainerBehorighetTest.php — här används
 * genomgående en `owner`, vars fulla behörighet den svitens
 * "alla tre rollerna ..."-test redan bevisar.
 */

it('skapar en container åt ett konto användaren är medlem i', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Vindil',
        'kind' => 'boat',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    $response->assertJson([
        'data' => [
            'name' => 'Vindil',
            'kind' => 'boat',
            'account' => $account->ulid,
        ],
    ]);
    expect($response->json('data.ulid'))->toBeString();
    expect($response->json('data.created_at'))->toBeString();
    expect($response->json('data.updated_at'))->toBeString();
    // Löpnumret exponeras aldrig, se issue 8 § Beslut 7.
    expect($response->json('data.id'))->toBeNull();

    expect(DB::table('container')->where('name', 'Vindil')->where('account_id', $account->id)->exists())->toBeTrue();
});

it('listar bara containers från konton användaren är medlem i', function () {
    [$eget, , $headers] = kontoMedMedlem();
    Container::factory()->for($eget, 'account')->create(['name' => 'Min båt']);

    $frammande = Account::factory()->create();
    Container::factory()->for($frammande, 'account')->create(['name' => 'Någon annans båt']);

    $response = getJson('/api/containers', $headers);

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.name'))->toBe('Min båt');
});

it('uppdaterar namn och kind', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['name' => 'Gammalt namn', 'kind' => 'boat']);

    $response = patchJson("/api/containers/{$container->ulid}", [
        'name' => 'Nytt namn',
        'kind' => 'caravan',
    ], $headers);

    $response->assertOk();
    $response->assertJson(['data' => ['name' => 'Nytt namn', 'kind' => 'caravan']]);

    $container->refresh();
    expect($container->name)->toBe('Nytt namn');
    expect($container->kind)->toBe('caravan');
});

it('account_id kan inte ändras via PATCH', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $annatKonto = Account::factory()->create();

    $response = patchJson("/api/containers/{$container->ulid}", [
        'name' => 'Fortfarande mitt',
        'account' => $annatKonto->ulid,
    ], $headers);

    $response->assertOk();

    $container->refresh();
    expect($container->account_id)->toBe($account->id);
    expect($container->account_id)->not->toBe($annatKonto->id);
});

it('template_source_id kan inte sättas via API:et', function () {
    [$account, , $headers] = kontoMedMedlem();
    $mall = Container::factory()->for($account, 'account')->create();

    $response = postJson('/api/containers', [
        'name' => 'Utstämplad',
        'kind' => 'boat',
        'account' => $account->ulid,
        'template_source_id' => $mall->id,
    ], $headers);

    $response->assertCreated();

    $skapad = Container::query()->where('ulid', $response->json('data.ulid'))->firstOrFail();
    expect($skapad->template_source_id)->toBeNull();
});

it('radering är mjuk', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();

    $response = deleteJson("/api/containers/{$container->ulid}", [], $headers);

    $response->assertNoContent();

    $rad = DB::table('container')->where('id', $container->id)->first();
    expect($rad)->not->toBeNull();
    expect($rad->deleted_at)->not->toBeNull();
});

it('en mjukraderad container ger 404 resource.not_found', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create();
    $container->delete();

    $response = getJson("/api/containers/{$container->ulid}", $headers);

    $response->assertStatus(404);
    expect($response->json('error.code'))->toBe('resource.not_found');
});

/*
 * Klart när: en art utanför den gamla listan tas emot, och CHECK-villkoret på
 * `container.kind` finns inte längre (issue 84 · [[ADR-0036 Containerns art]]).
 *
 * Provet på själva villkoret är ett prov på MIGRERINGEN och inte på databasen:
 * testsviten kör sqlite, och villkoret lades bara på mysql — sqlite saknar
 * ALTER TABLE ... ADD CONSTRAINT, se skapandemigreringen. Att raden går att
 * spara med `spaceship` bevisar att ingen grind står i vägen på vägen dit; att
 * villkoret är släppt bevisas i källan, för det är den enda platsen där
 * drivrutinen som kör provet kan se skillnad.
 */
it('släpper CHECK-villkoret och tar emot en art utanför den gamla listan', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Rymdskepp',
        'kind' => 'spaceship',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    $response->assertJson(['data' => ['name' => 'Rymdskepp', 'kind' => 'spaceship']]);

    $migreringar = [];

    foreach (File::allFiles(database_path('migrations')) as $fil) {
        $migreringar[] = $fil->getContents();
    }

    expect(implode("\n", $migreringar))->toContain('DROP CHECK container_kind_check');
});

/*
 * Klart när: de fem befintliga värdena är oförändrade i databasen efter
 * migreringen (issue 84).
 *
 * Migreringen SLÄPPER ett villkor; den skriver inte om en rad. Provet läser
 * källan för att bevisa att ingen skrivning finns där — samma sorts källprov
 * som CHECK-villkoret får ovan, och av samma skäl: sviten kör sqlite mot en
 * databas som byggts upp av migreringarna själva, så en rad som "överlevt" en
 * migrering kan den aldrig ha haft. De fem värdena prövas dessutom mot
 * databasen, för "oförändrade" förutsätter att de fortfarande går att spara.
 */
it('lämnar de fem befintliga arterna orörda genom migreringen', function () {
    $migrering = File::get(database_path('migrations/2026_09_18_000000_free_container_kind.php'));

    expect($migrering)->not->toMatch('/\b(UPDATE|DELETE|TRUNCATE)\b/');
    expect($migrering)->not->toMatch('/->(update|delete|insert|truncate)\(/');

    [$account] = kontoMedMedlem();

    // Raderna läggs direkt på modellen: provet gäller kolumnen efter
    // migreringen, inte containertaket, och gratisplanen har plats för en.
    foreach (['boat', 'caravan', 'house', 'car', 'other'] as $kind) {
        $container = Container::factory()->for($account, 'account')->create([
            'name' => "Arv {$kind}",
            'kind' => $kind,
        ]);

        expect($container->fresh()->kind)->toBe($kind);
    }
});

/*
 * Klart när: `Container::KINDS` finns inte längre i koden, och ingen `Rule::in`
 * validerar `kind` mot en fast lista (issue 84 · [[ADR-0036 Containerns art]]).
 *
 * Provet läser KÄLLAN, för det är formen regeln gäller: en konstant som ingen
 * definierat kan ingen körning leta efter. Kommentarer räknas inte — fem filer
 * pekar fortfarande på konstanten som mönster för en delad lista, och det är
 * döda referenser som issuen uttryckligen vill ha rapporterade i PR:en i
 * stället för rättade.
 */
it('har ingen sluten lista kvar för containerns kind', function () {
    $träffar = [];

    $filer = [
        ...File::allFiles(app_path()),
        ...File::allFiles(resource_path('js')),
        ...File::allFiles(database_path()),
    ];

    foreach ($filer as $fil) {
        if (! in_array($fil->getExtension(), ['php', 'js', 'vue'], true)) {
            continue;
        }

        if (preg_match('/\bKINDS\b/', kallaUtanKommentarer($fil)) === 1) {
            $träffar[] = $fil->getRelativePathname();
        }
    }

    expect($träffar)->toBe([]);

    // Reglerna för `kind` är längd och format — aldrig medlemskap i en mängd.
    foreach (['StoreContainerRequest', 'UpdateContainerRequest'] as $klass) {
        $kalla = File::get(app_path("Http/Requests/Container/{$klass}.php"));

        expect($kalla)->not->toContain('Rule::in');
        expect($kalla)->toContain("'kind'");
    }
});

/*
 * Klart när: `kind` får utelämnas vid skapande av en container (issue 84).
 *
 * Fältet är frivilligt — att tvinga fram en art är att ställa en fråga
 * användaren ännu inte kan svara på — och det som sparas är `null` och inte en
 * tom sträng.
 */
it('skapar en container utan kind', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Utan art',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.kind'))->toBeNull();
    expect(Container::query()->where('name', 'Utan art')->firstOrFail()->kind)->toBeNull();
});

/*
 * Klart när: ingen `match` eller `if` på containerns `kind` finns i `app/`
 * eller `resources/js/` (issue 84).
 *
 * Regeln står i App\Models\Container:s klasskommentar och skärps av issue 84:
 * ett FRITT fält som styr logik är värre än en sluten lista som gör det.
 * Provet läser källan, för regeln gäller kodformen — ingen körning kan se en
 * gren som ingen använt ännu.
 *
 * Bara CONTAINERNS kind prövas. `container_access.kind` och
 * `attachment.kind` är andra kolumner med egna regler; deras grenar är deras.
 */
it('grenar aldrig på containerns kind', function () {
    $träffar = [];

    $filer = [...File::allFiles(app_path()), ...File::allFiles(resource_path('js'))];

    foreach ($filer as $fil) {
        if (! in_array($fil->getExtension(), ['php', 'js', 'vue'], true)) {
            continue;
        }

        foreach (preg_split('/\R/', $fil->getContents()) as $rad) {
            if (preg_match('/\b(if|match|switch)\b/', $rad) !== 1) {
                continue;
            }

            if (preg_match('/container(?:->|\.)kind\b/i', $rad) === 1) {
                $träffar[] = $fil->getRelativePathname().': '.trim($rad);
            }
        }
    }

    expect($träffar)->toBe([]);
});

/**
 * Källkoden i $fil med kommentarer och docblock borttagna.
 *
 * PHP läses med `token_get_all()`, för en regex hade fällt på `//` inuti en
 * sträng. JS och Vue får nöja sig med block-, rad- och HTML-kommentarer.
 */
function kallaUtanKommentarer(SplFileInfo $fil): string
{
    $kalla = $fil->getContents();

    if ($fil->getExtension() === 'php') {
        $bitar = '';

        foreach (token_get_all($kalla) as $token) {
            if (! is_array($token)) {
                $bitar .= $token;

                continue;
            }

            $bitar .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1];
        }

        return $bitar;
    }

    $kalla = (string) preg_replace('#/\*.*?\*/#s', '', $kalla);
    $kalla = (string) preg_replace('#<!--.*?-->#s', '', $kalla);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kalla);
}

/*
 * Uppföljning på granskningen av PR #43: ContainerResource::toArray()
 * läser $this->account->ulid för varje rad — utan eager loading blir
 * listningen N+1. Se App\Http\Controllers\Api\ContainerController::index()
 * § with('account').
 *
 * Låser fast INTE ett fast frågeantal (skört mot ovidkommande ändringar,
 * t.ex. en extra fråga i whereHas-villkoret) utan att antalet frågor är
 * DETSAMMA oavsett hur många containers listan innehåller — kör samma
 * anrop två gånger, med fler containers andra gången, på minst två olika
 * konton båda gångerna, och jämför.
 */
it('listningen laddar ägarkontot i förväg', function () {
    [$kontoA, $user, $headers] = kontoMedMedlem();
    $kontoB = Account::factory()->create();
    $kontoB->users()->attach($user, ['role' => 'member']);

    Container::factory()->for($kontoA, 'account')->count(2)->create();
    Container::factory()->for($kontoB, 'account')->create();

    // "Värm" Sanctum-guarden med ett omätt anrop innan mätningen börjar.
    // Illuminate\Auth\RequestGuard::user() cachar den autentiserade
    // användaren efter FÖRSTA gången den slås upp (samma mekanism som
    // ContainerBehorighetTest dokumenterar) — utan den här värmningen
    // skulle det första mätta anropet bära en extra tokenuppslagsfråga
    // som det andra inte har, och skeva jämförelsen nedan helt oberoende
    // av eager loading.
    // Frys tiden runt mätningarna så UpdateLastActiveAt skriver deterministiskt
    // (issue 80). Carbon direkt i stället för travelTo() för att följa repots
    // konvention att inte skriva $this-> i it()-closures (se SkeletonTest.php
    // och SenasteAktivitetTest.php) — travelTo() vore fullt tillgängligt.
    Carbon::setTestNow(now());

    getJson('/api/containers', $headers)->assertOk();

    DB::enableQueryLog();
    $förstaSvaret = getJson('/api/containers', $headers);
    $frågorMedTreContainers = count(DB::getQueryLog());
    DB::flushQueryLog();

    $förstaSvaret->assertOk();
    expect($förstaSvaret->json('data'))->toHaveCount(3);

    // Fler containers, på samma två konton — frågeantalet ska INTE växa.
    Container::factory()->for($kontoA, 'account')->count(3)->create();
    Container::factory()->for($kontoB, 'account')->count(2)->create();
    DB::flushQueryLog(); // rensa bort factoryns egna INSERT-frågor innan mätningen

    $andraSvaret = getJson('/api/containers', $headers);
    $frågorMedÅttaContainers = count(DB::getQueryLog());
    DB::disableQueryLog();

    $andraSvaret->assertOk();
    expect($andraSvaret->json('data'))->toHaveCount(8);

    expect($frågorMedÅttaContainers)->toBe($frågorMedTreContainers);

    Carbon::setTestNow();
});

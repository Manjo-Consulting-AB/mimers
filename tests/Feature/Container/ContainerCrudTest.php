<?php

// rott-pa-basen: testfix, ingen kodändring (issue 80)

use App\Models\Account;
use App\Models\Container;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
 * "Klart när" (issue 88):
 * - `container` har en nullbar `description`
 * - `ContainerResource` bär fältet och alltid som `null` när det saknas
 * - en beskrivning som anges vid skapandet sparas
 * - en container kan skapas och sparas utan beskrivning
 * - en beskrivning kan sättas, ändras och tömmas
 * - ingen kod läser ut delar ur fältet
 * - inga andra kolumner har lagts till på `container`
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
 * tom sträng. Kolumnen är nullbar, och en tom sträng hade varit just den
 * sentinel [[ADR-0004 Fria taggar och kategorier]] vill undvika.
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
 * Klart när: `kind` får utelämnas vid skapande av en container (issue 84).
 *
 * Den andra halvan av samma fält: en container som HAR en art ska gå att
 * TÖMMA. `ConvertEmptyStringsToNull` gör en tom ruta till `null` innan
 * reglerna körs, `UpdateContainerRequest` lämnar den som `null`, och en nyckel
 * som inte skickas alls ska fortfarande betyda "rör inte arten" (`sometimes`).
 */
it('tömmer kind utan att röra en art som inte skickas', function () {
    [$account, , $headers] = kontoMedMedlem();

    $container = Container::factory()->for($account, 'account')->create(['kind' => 'Segelbåt']);

    patchJson("/api/containers/{$container->ulid}", ['kind' => ''], $headers)
        ->assertOk();

    expect($container->fresh()->kind)->toBeNull();

    // Modellinstansen bär fortfarande den gamla arten i minnet; utan refresh
    // ser Eloquent ingen ändring och skriver ingenting.
    $container->refresh()->update(['kind' => 'Segelbåt']);

    patchJson("/api/containers/{$container->ulid}", ['name' => 'Nytt namn'], $headers)
        ->assertOk();

    expect($container->fresh()->kind)->toBe('Segelbåt');
});

/*
 * Klart när: `kind` får utelämnas vid skapande av en container (issue 84).
 *
 * Blanksteg trimmas bort vid inmatningen ([[ADR-0016 Kostnadsregistrering]]
 * § Motivering: stavningsvarianter löses när de skrivs, inte i schemat), och
 * ett fält som bara var blanksteg är ingen art — det blir `null`. I övrigt
 * lagras värdet ORDAGRANT: ingen skiftlägesnormalisering, ingen hopslagning.
 */
it('trimmar kind men ändrar den inte i övrigt', function () {
    [$account, , $headers] = kontoMedMedlem();

    $container = Container::factory()->for($account, 'account')->create(['kind' => 'Segelbåt']);

    patchJson("/api/containers/{$container->ulid}", ['kind' => '  Segelbåt  '], $headers)
        ->assertOk();

    expect($container->fresh()->kind)->toBe('Segelbåt');

    patchJson("/api/containers/{$container->ulid}", ['kind' => '   '], $headers)
        ->assertOk();

    expect($container->fresh()->kind)->toBeNull();

    patchJson("/api/containers/{$container->ulid}", ['kind' => 'segelbåt'], $headers)
        ->assertOk();

    expect($container->fresh()->kind)->toBe('segelbåt');
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
 *
 * **En NÄRVAROKONTROLL fälls inte.** `v-if="container.kind"` frågar om fältet
 * är SATT — samma behandling som varje annat nullbart fält får — och inte
 * VILKEN art det är; granskningen av issue 84 beviljar den uttryckligen. Kvar
 * som brott står jämförelsen mot ett värde och uppslaget i `match`/`switch`.
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

            if (preg_match('/container(?:->|\.)kind\b/i', $rad) !== 1) {
                continue;
            }

            $jamforelse = preg_match('/container(?:->|\.)kind\b\s*(===|!==|==|!=|<=|>=|<|>)/i', $rad) === 1;
            $uppslag = preg_match('/\b(match|switch)\s*\([^)]*container(?:->|\.)kind/i', $rad) === 1;

            if ($jamforelse || $uppslag) {
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

/*
 * Issue 88 · Containern får en beskrivning. Se [[ADR-0039 Containerns
 * översikt]] § Beslut.
 *
 * Fältet är ETT fritextfält, nullbart och frivilligt, och det bärs av
 * ContainerResource som `null` när det saknas — aldrig utelämnat (issue 8
 * § Beslut 7: en klient som måste skilja "saknas" från "tomt" ska inte
 * behöva hantera två fall). Skapandevägen går genom
 * App\Actions\Container\CreateContainer, och parametern ligger SIST med ett
 * förval, så ingen befintlig anropare rördes.
 */

/*
 * Klart när: `container` har en nullbar `description` (issue 88).
 *
 * Nullbar är hela skillnaden mellan "användaren har skrivit något" och
 * "användaren har inte svarat" — samma kolumnform som `kind` fick i issue 84,
 * och `null` är det ärliga värdet i stället för den tomma sträng
 * [[ADR-0004 Fria taggar och kategorier]] vill undvika.
 */
it('bär beskrivningen som en nullbar kolumn på containern', function () {
    $kolumn = collect(Schema::getColumns('container'))->firstWhere('name', 'description');

    expect($kolumn)->not->toBeNull();
    expect($kolumn['nullable'])->toBeTrue();
});

/*
 * Klart när: inga andra kolumner har lagts till på `container` (issue 88).
 *
 * Beskrivningen är det ENDA fältet. Modell, årtal och tillverkare — den
 * formaterade underrubrik mockupen ville ha — byggs inte, varken nu eller som
 * förberedelse: en båt har årsmodell, ett kundprojekt har det inte, och
 * kolumnerna hade varit ett påstående om vad en container är
 * ([[ADR-0033 Produktens omfång]]).
 *
 * Provet läser kolumnlistan i sin helhet och inte bara frånvaron av ett namn:
 * "inga andra kolumner" är ett påstående om mängden, och en ny kolumn får
 * gärna vara medveten — men då ska det här provet uppdateras av den som
 * lägger till den.
 */
it('har inte fått någon annan kolumn än description', function () {
    $kolumner = collect(Schema::getColumns('container'))->pluck('name')->sort()->values()->all();

    expect($kolumner)->toBe([
        'account_id',
        'created_at',
        'currency',
        'deleted_at',
        'description',
        'id',
        'kind',
        'name',
        'template_source_id',
        'ulid',
        'updated_at',
    ]);
});

/*
 * Klart när: en beskrivning som anges vid skapandet sparas — via API:ets
 * `store()` (issue 88). Webbens halva prövas i
 * tests/Feature/Frontend/ContainervyerTest.php.
 *
 * Värdet är mockupens egen underrubrik, med punkten kvar. Den sparas
 * ORDAGRANT: ingen kod plockar isär fältet i modell och årtal, och hade den
 * gjort det hade den här raden kommit tillbaka i delar.
 */
it('skapar en container med en beskrivning', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Vindil',
        'description' => 'Malö 116 • 1984',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();
    expect($response->json('data.description'))->toBe('Malö 116 • 1984');

    expect(Container::query()->where('name', 'Vindil')->firstOrFail()->description)
        ->toBe('Malö 116 • 1984');
});

/*
 * Klart när: en container kan skapas och sparas UTAN beskrivning (issue 88).
 *
 * Fältet är frivilligt — att kräva en beskrivning vid skapandet är att ställa
 * en fråga användaren ännu inte kan svara på, samma resonemang som gjorde
 * `kind` frivillig i issue 84. Det som sparas är `null`, och svaret bär
 * nyckeln ändå.
 */
it('skapar en container utan beskrivning och svarar med null', function () {
    [$account, , $headers] = kontoMedMedlem();

    $response = postJson('/api/containers', [
        'name' => 'Utan beskrivning',
        'account' => $account->ulid,
    ], $headers);

    $response->assertCreated();

    expect($response->json('data'))->toHaveKey('description');
    expect($response->json('data.description'))->toBeNull();

    expect(Container::query()->where('name', 'Utan beskrivning')->firstOrFail()->description)
        ->toBeNull();
});

/*
 * Klart när: `ContainerResource` bär fältet och alltid som `null` när det
 * saknas, aldrig utelämnat (issue 88 · issue 8 § Beslut 7).
 *
 * Alla fyra svar som bär en container prövas: listan, den enskilda läsningen,
 * skapandet och uppdateringen. En resurs som utelämnar nyckeln tvingar
 * klienten att hantera "saknas" och "tomt" som två fall, och det är precis
 * vad Beslut 7 stänger.
 */
it('utelämnar aldrig beskrivningen i något svar', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['description' => null]);

    $lista = getJson('/api/containers', $headers)->assertOk();
    expect($lista->json('data.0'))->toHaveKey('description');
    expect($lista->json('data.0.description'))->toBeNull();

    $enskild = getJson("/api/containers/{$container->ulid}", $headers)->assertOk();
    expect($enskild->json('data'))->toHaveKey('description');
    expect($enskild->json('data.description'))->toBeNull();

    $skapad = postJson('/api/containers', [
        'name' => 'Ny',
        'account' => $account->ulid,
    ], $headers)->assertCreated();
    expect($skapad->json('data'))->toHaveKey('description');
    expect($skapad->json('data.description'))->toBeNull();

    $uppdaterad = patchJson("/api/containers/{$container->ulid}", [
        'name' => 'Nytt namn',
    ], $headers)->assertOk();
    expect($uppdaterad->json('data'))->toHaveKey('description');
    expect($uppdaterad->json('data.description'))->toBeNull();
});

/*
 * Klart när: en beskrivning kan sättas, ändras och tömmas (issue 88).
 *
 * Tömningen är samma mekanism som för `kind` i issue 84:
 * `ConvertEmptyStringsToNull` gör en tom ruta till `null` innan reglerna
 * körs, och kolumnen är nullbar. En nyckel som inte SKICKAS rör inte
 * beskrivningen — `sometimes` skiljer "töm den" från "ändra den inte".
 */
it('sätter, ändrar och tömmer beskrivningen', function () {
    [$account, , $headers] = kontoMedMedlem();
    $container = Container::factory()->for($account, 'account')->create(['description' => null]);

    patchJson("/api/containers/{$container->ulid}", ['description' => 'Malö 116 • 1984'], $headers)
        ->assertOk();
    expect($container->fresh()->description)->toBe('Malö 116 • 1984');

    patchJson("/api/containers/{$container->ulid}", ['description' => 'Såld 2019.'], $headers)
        ->assertOk();
    expect($container->fresh()->description)->toBe('Såld 2019.');

    patchJson("/api/containers/{$container->ulid}", ['description' => ''], $headers)
        ->assertOk();
    expect($container->fresh()->description)->toBeNull();

    // Modellinstansen bär fortfarande det gamla värdet i minnet; utan refresh
    // ser Eloquent ingen ändring och skriver ingenting.
    $container->refresh()->update(['description' => 'Kvar']);

    patchJson("/api/containers/{$container->ulid}", ['name' => 'Nytt namn'], $headers)
        ->assertOk();
    expect($container->fresh()->description)->toBe('Kvar');
});

/*
 * Klart när: ingen kod läser ut delar ur fältet (issue 88).
 *
 * Beskrivningen visas som den är skriven, och mockupens formaterade
 * underrubrik — *Malö 116 • 1984* — ska inte återuppstå som en parsning av
 * texten ([[ADR-0039 Containerns översikt]] § Beslut). Provet läser KÄLLAN,
 * för regeln gäller kodformen: ingen körning kan se en parsning som ännu inte
 * skrivits.
 *
 * Bara CONTAINERNS beskrivning prövas, och bara på den rad där fältet och en
 * uppdelningsfunktion möts — samma form och samma skäl som provet på `kind`
 * ovan. Att rita ut hela värdet, eller att fråga om det är SATT, är inte att
 * plocka isär det.
 */
it('plockar aldrig isär containerns beskrivning i delar', function () {
    $träffar = [];

    $filer = [...File::allFiles(app_path()), ...File::allFiles(resource_path('js'))];

    foreach ($filer as $fil) {
        if (! in_array($fil->getExtension(), ['php', 'js', 'vue'], true)) {
            continue;
        }

        foreach (preg_split('/\R/', kallaUtanKommentarer($fil)) as $rad) {
            if (preg_match('/\b(explode|preg_split|str_split|sscanf|substr|mb_substr|preg_match|preg_match_all)\s*\(/', $rad) !== 1) {
                continue;
            }

            if (preg_match('/container(?:->|\.)description\b/i', $rad) === 1) {
                $träffar[] = $fil->getRelativePathname().': '.trim($rad);
            }
        }
    }

    expect($träffar)->toBe([]);
});

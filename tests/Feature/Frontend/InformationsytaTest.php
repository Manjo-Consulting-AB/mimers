<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\DismissedTip;
use App\Models\User;
use App\Support\Tips;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\flushSession;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 128 · Informationsytan — tipsen användaren bläddrar igenom och kan
 * kryssa bort. Se App\Support\Tips, App\Models\DismissedTip,
 * App\Http\Controllers\DismissedTipController,
 * resources/js/components/InfoPanel.vue och [[ADR-0039 Containerns
 * översikt]] § Konsekvenser.
 *
 * Varje "Klart när"-punkt i issuen motsvaras av ett namngivet test här.
 *
 * **Ytan står på två sidor och svaret är detsamma.** Dashboarden och
 * containerns översikt bär samma `tips`-propp ur samma App\Support\Tips, och
 * proven jämför de två svaren i stället för att pröva dem var för sig: en
 * sida som byggde sin egen lista hade kunnat ge rätt form och ett annat
 * första tips.
 *
 * **Nyckeln är hela kontraktet.** Servern skickar nycklar och aldrig färdiga
 * meningar ([[ADR-0021 Frontendteknik]] § Beslut), och rubriken och
 * brödtexten slås upp ur `lang/en/ui.php` under `tips.{nyckel}.title` och
 * `tips.{nyckel}.body`. Provet prövar därför både att nycklarna kommer i rätt
 * ordning OCH att var och en har en sträng — en nyckel utan text är en tom
 * ruta i webbläsaren och ingenting en server-sida kan se.
 *
 * Hjälparna har prefixet `informationsyta` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Ett ägarkonto med en medlem i och en container, i ordningen
 * [$konto, $ägare, $container]. Containern behövs för containerns översikt —
 * ytan står på båda sidorna.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function informationsytaKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create();
    $konto->users()->attach($ägare, ['role' => 'owner']);

    return [$konto, $ägare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Tipsen ur svaret, som nycklar i serverns ordning.
 *
 * @return list<string>
 */
function informationsytaTips(TestResponse $svar): array
{
    /** @var array{tips: list<string>} $proppar */
    $proppar = $svar->inertiaProps();

    return $proppar['tips'];
}

/**
 * Kryssar bort ett tips genom rutten, som krysset gör — inte genom att skriva
 * raden förbi kontrollern. Ett prov som seedade `dismissed_tip` för hand hade
 * kunnat bevisa ytan mot ett tillstånd krysset aldrig kan producera.
 */
function informationsytaDolj(User $anvandare, string $nyckel, string $fran = '/dashboard'): TestResponse
{
    return actingAs($anvandare)->from($fran)->post("/tips/{$nyckel}/dismiss");
}

/**
 * Adressen till containerns översikt — den andra ytan panelen står på.
 */
function informationsytaOversikt(Container $container): string
{
    return "/containers/{$container->ulid}";
}

/*
 * Klart när: dashboarden och containerns översikt visar det första tipset
 * användaren inte dolt.
 *
 * Båda sidorna bär samma lista, och det första elementet är det ytan visar.
 * Ordningen prövas mot App\Support\Tips::KEYS och inte mot en avskrift här:
 * en lista som skrevs av i provet hade kunnat vara rätt medan klassen var fel.
 */
it('visar det första tipset användaren inte dolt på båda sidorna', function () {
    withoutVite();

    [, $ägare, $container] = informationsytaKontext();

    $dashboard = actingAs($ägare)->get('/dashboard')->assertOk();
    $översikt = actingAs($ägare)->get(informationsytaOversikt($container))->assertOk();

    expect(informationsytaTips($dashboard))->toBe(Tips::KEYS)
        ->and(informationsytaTips($översikt))->toBe(Tips::KEYS);

    // Det första tipset är detsamma på båda ytorna — panelen är EN komponent
    // och listan är EN lista.
    expect(Tips::KEYS[0])->toBe('containers');

    // Och den som kryssat bort det första möts av nästa, på båda sidorna.
    informationsytaDolj($ägare, 'containers')->assertRedirect('/dashboard');

    $dashboard = actingAs($ägare)->get('/dashboard')->assertOk();
    $översikt = actingAs($ägare)->get(informationsytaOversikt($container))->assertOk();

    expect(informationsytaTips($dashboard))->toBe(['structure', 'schedules'])
        ->and(informationsytaTips($översikt))->toBe(['structure', 'schedules']);

    // Panelen ritas av samma komponent på båda sidorna — samma fil, ingen
    // andra upplaga av ytan.
    expect(File::get(resource_path('js/pages/Dashboard.vue')))
        ->toContain('<InfoPanel')
        ->and(File::get(resource_path('js/pages/Containers/Overview.vue')))
        ->toContain('<InfoPanel');
});

/*
 * Klart när: tipsen kommer i den bestämda ordningen.
 *
 * Ordningen är klassens och inte databasens (issuens krav 3): raderna i
 * `dismissed_tip` har ingen ordning alls, och en lista som sorterades på
 * dem — eller på `created_at` — hade gett en annan ordning än `KEYS`. Provet
 * kryssar bort mittentipset och prövar att resten står kvar i sin ordning i
 * stället för att sluta sig samman.
 */
it('kommer i den bestämda ordningen', function () {
    withoutVite();

    [, $ägare] = informationsytaKontext();

    informationsytaDolj($ägare, 'structure')->assertRedirect('/dashboard');

    $svar = actingAs($ägare)->get('/dashboard')->assertOk();

    // `toBe` jämför ordningen och inte bara innehållet, och att det dolda
    // tipset var det MITTERSTA är hela poängen: en lista som byggdes i den
    // ordning raderna skrevs — eller i `created_at`-ordning — hade gett
    // ['schedules', 'containers'] eller tappat en rad.
    expect(informationsytaTips($svar))->toBe(['containers', 'schedules']);
});

/*
 * Klart när: ett dolt tips är dolt även i en ny session.
 *
 * Tillståndet bor i `dismissed_tip` och inte i webbläsaren (issuens krav 1).
 * Provet slänger sessionen mellan de två anropen: en lösning som mindes
 * krysset i sessionen hade fallit här, och en som mindes det i `localStorage`
 * hade aldrig kunnat svara rätt på servern.
 */
it('håller ett dolt tips dolt i en ny session', function () {
    withoutVite();

    [, $ägare, $container] = informationsytaKontext();

    informationsytaDolj($ägare, 'containers')->assertRedirect('/dashboard');

    flushSession();

    $svar = actingAs($ägare)->get('/dashboard')->assertOk();

    expect(informationsytaTips($svar))->toBe(['structure', 'schedules']);

    // Raden hör till personen och ligger i tabellen — det är därifrån svaret
    // kommer, och det är därför en annan webbläsare får samma svar.
    expect($ägare->dismissedTips()->where('tip_key', 'containers')->exists())->toBeTrue()
        ->and(DismissedTip::query()->where('user_id', $ägare->id)->count())->toBe(1);

    // En ANNAN användare är opåverkad: raden är personens och inte kontots.
    $kollega = User::factory()->create();
    $container->account->users()->attach($kollega, ['role' => 'member']);

    expect(informationsytaTips(actingAs($kollega)->get('/dashboard')->assertOk()))->toBe(Tips::KEYS);
});

/*
 * Klart när: ett nytt tips visas för den som dolt alla tidigare.
 *
 * Kravet är att tillståndet är per NYCKEL och inte per yta (issuens krav 2):
 * det finns ingen rad som säger "användaren har stängt panelen", bara en rad
 * per tips. Den som dolt allt har därför dolt tre nycklar och ingenting mer,
 * och en ny nyckel har ingen rad.
 *
 * Klassen får listan en senare release hade haft — det är hela skillnaden
 * mellan en ny nyckel och en gammal, och den går inte att ställa frågan om
 * mot dagens `Tips::KEYS` utan att ändra den.
 */
it('visar ett nytt tips för den som dolt alla tidigare', function () {
    withoutVite();

    [, $ägare] = informationsytaKontext();

    foreach (Tips::KEYS as $nyckel) {
        informationsytaDolj($ägare, $nyckel);
    }

    expect(informationsytaTips(actingAs($ägare)->get('/dashboard')->assertOk()))->toBe([]);

    $senare = new Tips([...Tips::KEYS, 'loans']);

    expect($senare->visibleFor($ägare))->toBe(['loans']);

    // Och den nya nyckeln står på sin plats i ordningen, inte i slutet:
    // `array_diff` bevarar ordningen i listan och inte i de dolda raderna.
    expect((new Tips(['loans', ...Tips::KEYS]))->visibleFor($ägare))->toBe(['loans']);
});

/*
 * Klart när: att dölja samma tips två gånger ger ingen andra rad och inget
 * fel.
 *
 * Det unika paret `(user_id, tip_key)` är garanten, och kontrollern fångar
 * `UniqueConstraintViolationException` i stället för att svara 500 — samma
 * form som stjärnan (issue 105). Båda svaren är omdirigeringen tillbaka.
 */
it('ger ingen andra rad och inget fel när samma tips döljs två gånger', function () {
    withoutVite();

    [, $ägare] = informationsytaKontext();

    informationsytaDolj($ägare, 'containers')->assertRedirect('/dashboard');
    informationsytaDolj($ägare, 'containers')->assertRedirect('/dashboard');

    expect(DismissedTip::query()->where('user_id', $ägare->id)->count())->toBe(1);

    // Och svaret från andra gången är detsamma som från den första: tipset är
    // dolt och nästa står först.
    expect(informationsytaTips(actingAs($ägare)->get('/dashboard')->assertOk()))
        ->toBe(['structure', 'schedules']);
});

/*
 * Klart när: en okänd nyckel ger 404.
 *
 * Grinden är `Tips::knows()`, och en nyckel utanför listan ska varken bli en
 * rad eller ett 500 — panelen får inte kunna dölja ett tips som inte finns,
 * och en framtida nyckel med samma namn ska inte vara dold i förväg.
 */
it('svarar 404 för en okänd nyckel och skriver ingen rad', function () {
    withoutVite();

    [, $ägare] = informationsytaKontext();

    informationsytaDolj($ägare, 'okand-nyckel')->assertNotFound();
    informationsytaDolj($ägare, 'containers-2')->assertNotFound();

    expect(DismissedTip::query()->count())->toBe(0);
});

/*
 * Rutten ligger bakom `auth`, som resten av webben: en utloggad besökare
 * skickas till inloggningen och når aldrig skrivningen. Eget prov och inte en
 * rad i provet ovan — `actingAs` autentiserar resten av testet, så en
 * gästkontroll där hade mötts av en inloggad session.
 */
it('skickar en utloggad besökare till inloggningen', function () {
    withoutVite();

    post('/tips/containers/dismiss')->assertRedirect('/login');

    expect(DismissedTip::query()->count())->toBe(0);
});

/*
 * Klart när: ytan ritas inte när alla tips är dolda.
 *
 * Servern svarar med en tom lista, och komponenten ritar ingenting alls —
 * ingen tom ram och ingen rubrik över ingenting. Inertia renderar mallen i
 * klienten, så den ritade ytan når aldrig svarskroppen i en testsvit; därför
 * prövas villkoret i källan, samma grepp som FavoritlistaTest.
 */
it('ritar inte ytan när alla tips är dolda', function () {
    withoutVite();

    [, $ägare, $container] = informationsytaKontext();

    foreach (Tips::KEYS as $nyckel) {
        informationsytaDolj($ägare, $nyckel);
    }

    expect(informationsytaTips(actingAs($ägare)->get('/dashboard')->assertOk()))->toBe([])
        ->and(informationsytaTips(actingAs($ägare)->get(informationsytaOversikt($container))->assertOk()))->toBe([]);

    $panel = File::get(resource_path('js/components/InfoPanel.vue'));

    expect($panel)->toContain('v-if="props.tips.length"')
        // Och krysset postar till ruttens nyckel och ingenting annat.
        ->toContain('router.post(')
        ->toContain('`/tips/${current.value}/dismiss`')
        // Vänteläget runt skrivningen (GenomgangTest): en knapp som går att
        // trycka två gånger är två onödiga anrop.
        ->toContain('onStart:')
        ->toContain('onFinish:');
});

/*
 * Klart när: migreringen följer konventionerna och går på MariaDB.
 *
 * Konventionerna är AGENTS.md § Databaskonventioner: `BIGINT UNSIGNED`
 * autoincrement, tidsstämplar, deklarerade främmande nycklar med RESTRICT,
 * och ingen `ulid` i en tabell som aldrig syns i API:et. Att den GÅR på
 * MariaDB prövas av CI-jobbet `Migreringar`, som kör `up()` mot
 * mariadb:10.6 — testsviten kör sqlite och kan inte svara på det.
 */
it('följer databaskonventionerna', function () {
    expect(Schema::hasTable('dismissed_tip'))->toBeTrue();

    foreach (['id', 'user_id', 'tip_key', 'created_at', 'updated_at'] as $kolumn) {
        expect(Schema::hasColumn('dismissed_tip', $kolumn))->toBeTrue("dismissed_tip.{$kolumn} saknas");
    }

    // Ingen `ulid`: raderna syns aldrig i API:et, och ingen rutt identifierar
    // en enskild rad.
    expect(Schema::hasColumn('dismissed_tip', 'ulid'))->toBeFalse();

    // Ingen `deleted_at`: ett dolt tips är inget innehåll att återställa.
    expect(Schema::hasColumn('dismissed_tip', 'deleted_at'))->toBeFalse();

    // Det unika paret är garanten för "en rad per person och tips".
    $unikt = collect(Schema::getIndexes('dismissed_tip'))
        ->firstWhere('columns', ['user_id', 'tip_key']);

    expect($unikt)->not->toBeNull()
        ->and($unikt['unique'])->toBeTrue();

    // Främmande nyckel mot `user`, och RESTRICT: tabellen hör till personen,
    // och personraderingen ska mötas av en post att ta ställning till.
    $nyckel = collect(Schema::getForeignKeys('dismissed_tip'))->firstWhere('columns', ['user_id']);

    expect($nyckel)->not->toBeNull()
        ->and($nyckel['foreign_table'])->toBe('user')
        ->and($nyckel['on_delete'])->toBe('restrict');

    // Och varje nyckel i listan ryms i kolumnen den bor i — en ny nyckel
    // längre än `VARCHAR(60)` är ett schemafel som annars syns först i drift.
    expect(max(array_map('strlen', Tips::KEYS)))->toBeLessThanOrEqual(60);
});

/*
 * Klart när: [[Konton och åtkomst]] har ett avsnitt `dismissed_tip`, och
 * [[Registerförteckning]] har en rad för tabellen.
 *
 * Dokumentproven är textprov med flit: avsnittet är en del av kontraktet mot
 * den som bygger personraderingen, och en tabell som bara finns i koden hade
 * inte kommit med då.
 */
it('står i datamodellen och i registerförteckningen', function () {
    $konton = File::get(base_path('docs/Datamodell/Konton och åtkomst.md'));

    expect($konton)->toContain('## dismissed_tip')
        ->toContain('(user_id, tip_key)')
        ->toContain('App\Support\Tips::KEYS');

    $register = File::get(base_path('docs/Registerförteckning.md'));

    expect($register)->toContain('| Dolt tips | `dismissed_tip` |');
});

/*
 * Klart när: strängarna ligger i `lang/en/ui.php`.
 *
 * En rubrik och en brödtext per nyckel i App\Support\Tips, plus panelens tre
 * egna ord. Nyckeln är strängens adress — `tips.{nyckel}.title` och
 * `tips.{nyckel}.body` — så en ny nyckel i klassen utan text faller här och
 * inte som en tom ruta i webbläsaren.
 *
 * Texten är högst två meningar per tips (issuens krav): panelen är en knuff
 * och inte en handbok. Provet räknar meningsslut och inte ord, för det är
 * antalet meningar som är kravet.
 */
it('har en rubrik och en brödtext per tips i lang/en/ui.php', function () {
    foreach (['previous', 'next', 'dismiss'] as $nyckel) {
        expect(Lang::get("ui.tips.{$nyckel}", [], 'en'))->not->toBe("ui.tips.{$nyckel}", "ui.tips.{$nyckel} saknas");
    }

    foreach (Tips::KEYS as $nyckel) {
        $rubrik = Lang::get("ui.tips.{$nyckel}.title", [], 'en');
        $brodtext = Lang::get("ui.tips.{$nyckel}.body", [], 'en');

        expect($rubrik)->not->toBe("ui.tips.{$nyckel}.title", "ui.tips.{$nyckel}.title saknas")
            ->and($brodtext)->not->toBe("ui.tips.{$nyckel}.body", "ui.tips.{$nyckel}.body saknas")
            ->and(trim($rubrik))->not->toBe('')
            ->and(trim($brodtext))->not->toBe('');

        $meningar = preg_match_all('/[.!?](?:\s|$)/', $brodtext);

        expect($meningar)->toBeLessThanOrEqual(2, "ui.tips.{$nyckel}.body är mer än två meningar");
    }
});

/*
 * Klart när: hela testsviten är grön — och panelen ritas på båda sidorna.
 *
 * Sidprovet: att proppen FYLLS på containerns översikt. Att den finns där är
 * ADR-0039 § Konsekvenser, och en propp som bara dashboarden skickade hade
 * gett en tom yta på containerns sida utan att något annat prov föll.
 */
it('bär tipsen på containerns översikt', function () {
    withoutVite();

    [, $ägare, $container] = informationsytaKontext();

    actingAs($ägare)->get(informationsytaOversikt($container))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Overview')
            ->has('tips', count(Tips::KEYS))
            ->where('tips', Tips::KEYS)
        );
});

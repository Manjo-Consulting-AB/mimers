<?php

use App\Actions\Schedule\ListTodo;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M28 · issue 782 · Filter och sortering på `/tasks`, se
 * [[M28 Testarnas fynd 2026-10-07]] och `docs/Design/task-dashboard.png`.
 *
 * **Filtren står i adressen** (Beslut 1): `container`, `gtd`, `status` och
 * `sort`, bredvid `list` och markören. Ett okänt värde ignoreras, och en
 * container användaren inte når ger en TOM lista och inte ett fel — urvalet går
 * fortfarande genom `scopeTodoFor()`.
 *
 * Filen prövar de åtta gränser issuen är byggd kring, en per punkt i
 * "Klart när":
 *
 * 1. Varje filter avgränsar listan: containern, listan och statusen.
 * 2. En container användaren inte når ger en tom lista — inte ett fel.
 * 3. Filtret som fliken redan bestämmer ignoreras (Beslut 2).
 * 4. Sorteringen vänder ordningen, och grupperna följer raderna (Beslut 3).
 * 5. Nästa sida behåller filtren och sorteringen.
 * 6. Panelens tal påverkas inte av filtren (Beslut 4).
 *
 * Hjälparna med prefixet `todovy` och `paginering` kommer ur
 * tests/Feature/Frontend/TodovyTest.php och TaskpagineringTest.php — Pest
 * lägger alla testfiler i samma namnrymd. Bara hjälparna med prefixet
 * `uppgiftsfilter` är nya här, och de bygger rader som `pagineringRader()`
 * gör: samma item, samma förfallodag, i skapelseordning.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]), av
 * samma skäl som i de två filerna ovan: listan grupperas mot användarens dag
 * medan `todovyDatum()` räknar ur serverns klocka, och mellan klockan 22 och
 * 24 UTC är de två olika datum. Utan pinnen vore filen grön på dagen och röd
 * på natten.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * $antal öppna förekomster med en given `gtd_list` och status, på samma item
 * och samma förfallodag, i skapelseordning.
 *
 * ULID:n är monoton i tiden (Symfony Ulid::generate()), så skapelseordningen ÄR
 * `ulid`-ordningen — och `ulid` är frågans andra nyckel. Det är därför följden
 * går att pröva mot en känd lista i stället för mot en sortering provet gör.
 *
 * @return list<string> förekomsternas ULID:n, i `ulid`-ordning
 */
function uppgiftsfilterRader(Item $item, string $due, string $gtd, string $status, int $antal, string $namn): array
{
    $ulider = [];

    foreach (range(1, $antal) as $i) {
        [, $rad] = todovyUppgift($item, $due, "{$namn} {$i}", [
            'gtd_list' => $gtd,
            'status' => $status,
        ]);

        $ulider[] = $rad->ulid;
    }

    return $ulider;
}

/**
 * En container i en ANNAN kontofamilj, med öppna uppgifter i. Användaren är
 * varken medlem eller mottagare, så hon når den inte.
 */
function uppgiftsfilterFrammande(int $antal): Container
{
    $container = todovyPärm(Account::factory()->create());

    uppgiftsfilterRader(todovyItem($container, 'Hemlig motor'), todovyDatum(10), 'next', 'open', $antal, 'Hemlig uppgift');

    return $container;
}

/**
 * Adressen till en sida av listan, med filtren i querysträngen. Ordningen är
 * den kontrollern skriver dem i, och `[]` är listan utan ett enda filter.
 *
 * @param  array<string, string|int>  $params
 */
function uppgiftsfilterUrl(array $params = []): string
{
    return '/tasks'.($params === [] ? '' : '?'.http_build_query($params));
}

/**
 * Alla rader i svaret i gruppernas RITNINGSORDNING — nycklarnas ordning i
 * svaret, som är sorteringens (Beslut 3).
 *
 * `todovyRader()` och `pagineringAlla()` samlar grupperna i en FAST ordning
 * (försenat först) och svarar därför på "vilka rader finns", inte på "i vilken
 * ordning ritas de". Den frågan är sorteringens, och den här hjälparen ställer
 * den.
 *
 * @return list<array<string, mixed>>
 */
function uppgiftsfilterRitade(TestResponse $svar): array
{
    /** @var array<string, list<array<string, mixed>>> $grupper */
    $grupper = $svar->inertiaProps()['groups'];

    $rader = [];

    foreach ($grupper as $entries) {
        $rader = [...$rader, ...$entries];
    }

    return $rader;
}

// --- containern ------------------------------------------------------------

/*
 * Klart när: `filtret container avgränsar listan`.
 *
 * Två containrar i samma konto, med olika många uppgifter, och samma
 * förfallodag så att `ulid` är den enda nyckeln som skiljer raderna. Utan
 * filter bär listan båda containrarnas rader i skapelseordning; med
 * `?container=` bara den enas — och filtret står kvar i svaret, så nästa sidas
 * länk kan byggas ur det.
 */
it('filtret container avgränsar listan', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    $motorn = todovyPärm($konto);
    $seglet = todovyPärm($konto);

    $iMotorn = uppgiftsfilterRader(todovyItem($motorn, 'Motorn'), todovyDatum(10), 'next', 'open', 2, 'Motorn');
    $iSeglet = uppgiftsfilterRader(todovyItem($seglet, 'Seglet'), todovyDatum(10), 'next', 'open', 3, 'Seglet');

    $oavgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl())->assertOk();

    expect(pagineringUlider(todovyRader($oavgränsad)))->toBe([...$iMotorn, ...$iSeglet]);

    $avgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl(['container' => $motorn->ulid]))->assertOk();

    expect(pagineringUlider(todovyRader($avgränsad)))->toBe($iMotorn)
        ->and($avgränsad->inertiaProps()['filters']['container'])->toBe($motorn->ulid)
        // Menyn bär båda containrarna — filtret tar bort rader och inte val.
        // Ordningen är namnets och inte skapelsens, så mängden prövas.
        ->and(array_column($avgränsad->inertiaProps()['containers'], 'ulid'))
        ->toEqualCanonicalizing([$motorn->ulid, $seglet->ulid]);
});

/*
 * Klart när: `en container användaren inte når ger en tom lista`.
 *
 * Filtret går genom `scopeTodoFor()` precis som allt annat (Beslut 1), så
 * åtkomsten formuleras på ett ställe och en container utanför omfånget ger
 * ingenting — inte ett fel. `hasContainers` svarar fortfarande ja: användaren
 * HAR en container, och den här är inte hennes.
 */
it('en container användaren inte når ger en tom lista', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    uppgiftsfilterRader($item, todovyDatum(10), 'next', 'open', 2, 'Min uppgift');

    $frammande = uppgiftsfilterFrammande(3);

    $svar = actingAs($anvandare)->get(uppgiftsfilterUrl(['container' => $frammande->ulid]))->assertOk();

    expect(todovyRader($svar))->toBe([])
        ->and($svar->getContent())->not->toContain('Hemlig')
        ->and($svar->inertiaProps()['hasContainers'])->toBeTrue()
        // Filtret TILLÄMPADES — det står kvar som det filter det är, för
        // nästa sidas länk ska bära samma fråga.
        ->and($svar->inertiaProps()['filters']['container'])->toBe($frammande->ulid);
});

// --- listan och statusen ---------------------------------------------------

/*
 * Klart när: `filtret gtd avgränsar listan på active`.
 *
 * *Active* är frånvaron av `?list=`, och där ritas listväljaren: de tre lagrade
 * listorna, och ingenting annat. Filtret prövar `gtd_list`-kolumnen, så en rad
 * i *Waiting* faller bort när man väljer *Next*.
 */
it('filtret gtd avgränsar listan på active', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $next = uppgiftsfilterRader($item, todovyDatum(10), 'next', 'open', 2, 'Next');
    $waiting = uppgiftsfilterRader($item, todovyDatum(10), 'waiting', 'open', 3, 'Waiting');
    $someday = uppgiftsfilterRader($item, todovyDatum(10), 'someday', 'open', 1, 'Someday');

    $oavgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl())->assertOk();

    expect(pagineringUlider(todovyRader($oavgränsad)))->toBe([...$next, ...$waiting, ...$someday]);

    $avgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl(['gtd' => 'waiting']))->assertOk();

    expect(pagineringUlider(todovyRader($avgränsad)))->toBe($waiting)
        ->and($avgränsad->inertiaProps()['filters']['gtd'])->toBe('waiting');
});

/*
 * Klart när: `filtret gtd ignoreras på fliken next`.
 *
 * Fliken ÄR listan (Beslut 2): väljaren ritas inte där, och en adress som ändå
 * bär `gtd` ritar samma lista som fliken valde. Filtret står som `null` i
 * svaret, så det varken ritas eller följer med i nästa sidas länk.
 */
it('filtret gtd ignoreras på fliken next', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $next = uppgiftsfilterRader($item, todovyDatum(10), 'next', 'open', 2, 'Next');
    uppgiftsfilterRader($item, todovyDatum(10), 'waiting', 'open', 3, 'Waiting');

    $svar = actingAs($anvandare)->get(uppgiftsfilterUrl(['list' => 'next', 'gtd' => 'waiting']))->assertOk();

    expect(pagineringUlider(todovyRader($svar)))->toBe($next)
        ->and($svar->inertiaProps()['list'])->toBe('next')
        ->and($svar->inertiaProps()['filters']['gtd'])->toBeNull();

    // *Done* står utanför listfiltret av ett annat skäl (Beslut 2): raden där
    // kommer ur en egen fråga och har ingen `gtd_list` alls, så filtret
    // tillämpas inte och väljaren ritas inte.
    $klart = actingAs($anvandare)->get(uppgiftsfilterUrl(['list' => 'done', 'gtd' => 'waiting']))->assertOk();

    expect($klart->inertiaProps()['filters']['gtd'])->toBeNull();
});

/*
 * Klart när: `filtret status avgränsar listan`.
 *
 * Statusen är förekomstens egen kolumn (ADR-0052 § 1) och inte en lista:
 * `open` och `in_progress` är de två aktiva värdena, och listan utan filter
 * bär båda.
 */
it('filtret status avgränsar listan', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    uppgiftsfilterRader($item, todovyDatum(10), 'next', 'open', 2, 'Öppen');
    $pagaende = uppgiftsfilterRader($item, todovyDatum(10), 'next', 'in_progress', 3, 'Pågående');

    $oavgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl())->assertOk();

    expect(pagineringUlider(todovyRader($oavgränsad)))->toHaveCount(5);

    $avgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl(['status' => 'in_progress']))->assertOk();

    expect(pagineringUlider(todovyRader($avgränsad)))->toBe($pagaende)
        ->and($avgränsad->inertiaProps()['filters']['status'])->toBe('in_progress');
});

// --- sorteringen -----------------------------------------------------------

/*
 * Klart när: `fallande sortering vänder ordningen` — första raden har senast
 * `due_at`.
 *
 * Fyra rader: en försenad, två kommande och en utan datum. **Grupperna följer
 * raderna** (Beslut 3), så den stigande listan ritar *Overdue* först och den
 * fallande *Upcoming* — gruppordningen är nycklarnas ordning i svaret, och vyn
 * itererar dem som de kommer.
 *
 * **Den odaterade raden ligger sist i BÅDA riktningarna.** Sektionen står kvar:
 * en rad utan datum har inget datum att vända på (ADR-0052 § 3), och den hör
 * till listans slut oavsett åt vilket håll de daterade raderna pekar.
 */
it('fallande sortering vänder ordningen', function () {
    withoutVite();

    [, $anvandare, , $item] = todovyKontext();

    $senast = pagineringRader($item, todovyDatum(20), 1, 'Senast');
    $mitten = pagineringRader($item, todovyDatum(10), 1, 'I mitten');
    $tidigast = pagineringRader($item, todovyDatum(-5), 1, 'Försenad');
    $odaterad = pagineringUtanDatum($item, 1, 'Utan datum');

    $stigande = actingAs($anvandare)->get(uppgiftsfilterUrl(['sort' => 'due_asc']))->assertOk();

    expect(pagineringUlider(uppgiftsfilterRitade($stigande)))->toBe([...$tidigast, ...$mitten, ...$senast, ...$odaterad])
        ->and(array_keys($stigande->inertiaProps()['groups']))
        ->toBe(['overdue', 'today', 'this_week', 'upcoming', 'no_date']);

    $fallande = actingAs($anvandare)->get(uppgiftsfilterUrl(['sort' => 'due_desc']))->assertOk();

    // Första raden har senast `due_at`, och grupperna följer raderna: *Upcoming*
    // ritas först, *No date* sist — som förut.
    expect(uppgiftsfilterRitade($fallande)[0]['due_at'])->toBe(todovyDatum(20))
        ->and(pagineringUlider(uppgiftsfilterRitade($fallande)))->toBe([...$senast, ...$mitten, ...$tidigast, ...$odaterad])
        ->and(array_keys($fallande->inertiaProps()['groups']))
        ->toBe(['upcoming', 'this_week', 'today', 'overdue', 'no_date'])
        ->and($fallande->inertiaProps()['filters']['sort'])->toBe('due_desc');

    // Utan `sort` gäller förvalet, som är den stigande ordningen.
    $förval = actingAs($anvandare)->get(uppgiftsfilterUrl())->assertOk();

    expect($förval->inertiaProps()['filters']['sort'])->toBe(ListTodo::SORT_DUE_ASC)
        ->and(pagineringUlider(uppgiftsfilterRitade($förval)))->toBe(pagineringUlider(uppgiftsfilterRitade($stigande)));
});

// --- bläddringen -----------------------------------------------------------

/*
 * Klart när: `nästa sida behåller filtren och sorteringen` — `nextUrl` bär
 * parametrarna.
 *
 * En rad mer än en sida, så att nästa sida FINNS. Markörens adress byggs av
 * kontrollern ur de filter actionen TILLÄMPADE, och den bär därför samma fyra
 * parametrar som frågan kom in med — annars hade sida två svarat på en annan
 * fråga än sida ett. Den fallande ordningen gör dessutom den här sidan till de
 * femtio SISTA raderna i `ulid`-ordning.
 */
it('nästa sida behåller filtren och sorteringen', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    $container = todovyPärm($konto);
    $item = todovyItem($container, 'Motorn');

    $rader = uppgiftsfilterRader($item, todovyDatum(10), 'next', 'open', ListTodo::PER_PAGE + 1, 'Uppgift');

    $sida1 = actingAs($anvandare)->get(uppgiftsfilterUrl([
        'container' => $container->ulid,
        'gtd' => 'next',
        'status' => 'open',
        'sort' => 'due_desc',
    ]))->assertOk();

    $nextUrl = $sida1->inertiaProps()['nextUrl'];

    expect($nextUrl)->toStartWith('/tasks?');
    expect($nextUrl)->toContain('container='.$container->ulid);
    expect($nextUrl)->toContain('gtd=next');
    expect($nextUrl)->toContain('status=open');
    expect($nextUrl)->toContain('sort='.ListTodo::SORT_DUE_DESC);
    expect($nextUrl)->toContain(ListTodo::CURSOR_AFTER.'=');

    $sida2 = actingAs($anvandare)->get($nextUrl)->assertOk();

    expect(pagineringUlider(todovyRader($sida1)))->toBe(array_slice(array_reverse($rader), 0, ListTodo::PER_PAGE))
        ->and(pagineringUlider(todovyRader($sida2)))->toBe(array_slice(array_reverse($rader), ListTodo::PER_PAGE))
        ->and($sida2->inertiaProps()['filters'])->toBe([
            'container' => $container->ulid,
            'gtd' => 'next',
            'status' => 'open',
            'sort' => 'due_desc',
        ]);
});

/*
 * Klart när: `bläddrar bakåt i fallande ordning till samma sida` — den
 * spegelvända markören.
 *
 * Markören pekar i sorteringens riktning (Beslut 3), och den riktningen är
 * omvänd mot den som fanns före issuen. Sextio daterade och tjugo odaterade ger
 * två sidor i fallande ordning: sidan ett bär de femtio SENASTE daterade, sidan
 * två de tio daterade som är kvar och alla tjugo odaterade — sektionen står
 * kvar sist. Föregående-länken leder tillbaka till sida ett, ordagrant: en rad
 * för mycket, en rad för lite eller en överlappning faller på samma rad.
 */
it('bläddrar bakåt i fallande ordning till samma sida', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();
    $item = todovyItem(todovyPärm($konto), 'Motorn');

    $daterade = uppgiftsfilterRader($item, todovyDatum(-1), 'next', 'open', 60, 'Daterad');
    $odaterade = pagineringUtanDatum($item, 20, 'Utan datum');

    // Den ritade ordningen i fallande riktning: daterade fallande, därefter de
    // odaterade — som också är en lista och därför vänds.
    $ritade = [...array_reverse($daterade), ...array_reverse($odaterade)];

    $sida1 = actingAs($anvandare)->get(uppgiftsfilterUrl(['sort' => 'due_desc']))->assertOk();
    $sida2 = actingAs($anvandare)->get($sida1->inertiaProps()['nextUrl'])->assertOk();

    expect(pagineringUlider(uppgiftsfilterRitade($sida1)))->toBe(array_slice($ritade, 0, ListTodo::PER_PAGE))
        ->and(pagineringUlider(uppgiftsfilterRitade($sida2)))->toBe(array_slice($ritade, ListTodo::PER_PAGE))
        ->and($sida2->inertiaProps()['nextUrl'])->toBeNull();

    $tillbaka = actingAs($anvandare)->get($sida2->inertiaProps()['previousUrl'])->assertOk();

    expect(pagineringUlider(uppgiftsfilterRitade($tillbaka)))->toBe(pagineringUlider(uppgiftsfilterRitade($sida1)));
});

// --- panelen ---------------------------------------------------------------

/*
 * Klart när: `panelens tal påverkas inte av filtren`.
 *
 * `gtdCounts()` känner inte filtren (Beslut 4): talet är användarens hela
 * GTD-läge och inte det hon valt att visa just nu — samma regel som för växeln
 * `show_upcoming_tasks`. En avgränsad lista ritar därför samma tal som en
 * oavgränsad, och de är talen för ALLA listor och inte för den valda.
 */
it('panelens tal påverkas inte av filtren', function () {
    withoutVite();

    [$konto, $anvandare] = todovyKonto();

    $container = todovyPärm($konto);
    $item = todovyItem($container, 'Motorn');

    uppgiftsfilterRader($item, todovyDatum(-1), 'next', 'open', 2, 'Försenad');
    uppgiftsfilterRader($item, todovyDatum(2), 'waiting', 'open', 3, 'Väntande');
    uppgiftsfilterRader($item, todovyDatum(2), 'someday', 'in_progress', 4, 'Someday');

    $oavgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl())->assertOk();

    $tal = $oavgränsad->inertiaProps()['counts'];

    expect($tal['next'])->toBe(2)
        ->and($tal['waiting'])->toBe(3)
        ->and($tal['someday'])->toBe(4)
        ->and($tal['in_progress'])->toBe(4);

    $avgränsad = actingAs($anvandare)->get(uppgiftsfilterUrl([
        'container' => $container->ulid,
        'gtd' => 'someday',
        'status' => 'in_progress',
    ]))->assertOk();

    expect($avgränsad->inertiaProps()['counts'])->toBe($tal);
});

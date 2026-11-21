<?php

use App\Models\Account;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 428 · Agentens handlingar i historiken, se
 * App\Actions\Audit\PresentAuditEvents, App\Actions\Audit\ListAuditEvents,
 * resources/js/components/HistoryRow.vue,
 * resources/js/components/HistoryFilterBar.vue och [[ADR-0087 MCP-server för
 * agenter]] Riktning 3 och 8.
 *
 * **Formen prövas genom ytorna och inte genom actionen direkt.** Ett prov som
 * anropar `PresentAuditEvents::handle()` med en handplockad kollektion hade
 * inte bevisat att `ListAuditEvents` eager-laddar `agent` — och det är just
 * den kopplingen som gör att frågorna inte växer per rad. Varje prov går
 * därför via rutten och läser propparna.
 *
 * **Filtret prövas där det bor.** Precis som i HistorikfilterTest går proven
 * via `GET`-rutterna och jämför de RADER som kommer ut, så ett filter som
 * sållade fel rad hade fallit. Talen (`stats`) prövas i samma svep: de byggs
 * ur samma `filtered()`.
 *
 * **Filen räknar frågor och fryser därför tiden**, se
 * tests/Feature/Testinfrastruktur/FragerakningTest.php.
 *
 * Hjälparna har prefixet `agenthistorik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem som äger en container.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function agenthistorikKontext(): array
{
    $konto = Account::factory()->create();
    $ägare = User::factory()->create(['name' => 'Anna']);
    $konto->users()->attach($ägare, ['role' => 'owner']);
    $container = Container::factory()->for($konto, 'account')->create(['name' => 'Pärmen']);

    return [$konto, $ägare, $container];
}

/**
 * En ansluten agent utan rättigheter — raden räcker för historiken.
 */
function agenthistorikAgent(User $ägare, Account $konto, string $namn): Agent
{
    $agent = new Agent;
    $agent->user_id = $ägare->id;
    $agent->account_id = $konto->id;
    $agent->oauth_client_id = (string) Str::uuid();
    $agent->name = $namn;
    $agent->save();

    return $agent->refresh();
}

/**
 * En loggrad skriven DIREKT i loggen, med agenten när en handlade.
 */
function agenthistorikRad(
    Container $container,
    Account $account,
    User $handlande,
    ?Agent $agent = null,
    string $action = AuditLog::ACTION_ITEM_UPDATED,
    ?Carbon $när = null,
): AuditLog {
    return AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $handlande->id,
        'agent_id' => $agent?->id,
        'action' => $action,
        'created_at' => $när ?? now(),
    ]);
}

/**
 * Sidans proppar ur Inertias rotvy.
 *
 * @return array<string, mixed>
 */
function agenthistorikProps(TestResponse $svar): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = $svar->viewData('page');

    return $sida['props'];
}

/**
 * Dagarnas rader som en platt lista, nyast först.
 *
 * @param  array<string, mixed>  $proppar
 * @return list<array<string, mixed>>
 */
function agenthistorikRader(array $proppar): array
{
    $rader = [];

    /** @var list<array{date: string, rows: list<array<string, mixed>>}> $dagar */
    $dagar = $proppar['days'];

    foreach ($dagar as $dag) {
        foreach ($dag['rows'] as $rad) {
            $rader[] = $rad;
        }
    }

    return $rader;
}

/**
 * ULID:erna i en lista av val eller rader, i ordning.
 *
 * @param  list<array<string, mixed>>  $poster
 * @return list<string>
 */
function agenthistorikUlids(array $poster): array
{
    return array_map(static fn (array $post): string => (string) $post['ulid'], $poster);
}

/**
 * Summan av `perType` — antalet händelser talen räknar.
 *
 * @param  list<array{type: string|null, count: int}>  $perType
 */
function agenthistorikTypsumma(array $perType): int
{
    return array_sum(array_map(static fn (array $typ): int => $typ['count'], $perType));
}

/**
 * Antalet frågor under ett anrop.
 */
function agenthistorikFragor(Closure $anrop): int
{
    $antal = 0;

    DB::listen(function (QueryExecuted $query) use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

/**
 * En containerbred åtkomst på `write` för en gäst — samma form som
 * historikGast() i HistorikflikTest.
 */
function agenthistorikGast(Container $container, string $namn): User
{
    $gäst = User::factory()->create(['name' => $namn]);

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $gäst->id,
        'level' => 'write',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gäst;
}

/*
 * Klart när: en rad som en agent skrev bär `agent: {ulid, name}`, och en rad
 * som personen skrev själv bär `agent: null`.
 *
 * De två raderna skiljer sig bara i kolumnen, så ett svar som alltid satte en
 * agent — eller alltid `null` — faller. Namnet slås upp ur relationen när
 * raden läses, precis som `user`.
 */
it('en agentrad bär agentens ulid och namn, och en personrad bär null', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = agenthistorikKontext();
    $agent = agenthistorikAgent($ägare, $konto, 'Claude');

    $själv = agenthistorikRad($pärm, $konto, $ägare, när: now()->subMinute());
    $viaAgent = agenthistorikRad($pärm, $konto, $ägare, $agent, när: now());

    $rader = agenthistorikRader(agenthistorikProps(
        actingAs($ägare)->get("/containers/{$pärm->ulid}/history")->assertOk(),
    ));

    expect(agenthistorikUlids($rader))->toBe([$viaAgent->ulid, $själv->ulid])
        ->and($rader[0]['agent'])->toBe([
            'ulid' => $agent->ulid,
            'name' => 'Claude',
        ])
        ->and($rader[1]['agent'])->toBeNull();
});

/*
 * Klart när: en rad vars agent har tagits bort bär `{ulid: null, name: null}`.
 *
 * Kolumnen har ingen främmande nyckel (ADR-0087), så raden överlever agenten.
 * Relationen svarar `null` — precis som för en rad utan agent — och det är
 * `agent_id` som skiljer de två. Utan den skillnaden hade raden påstått att
 * personen handlade själv.
 */
it('en rad vars agent tagits bort bär tomma fält', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = agenthistorikKontext();
    $agent = agenthistorikAgent($ägare, $konto, 'Claude');

    agenthistorikRad($pärm, $konto, $ägare, $agent);

    $agent->delete();

    $rader = agenthistorikRader(agenthistorikProps(
        actingAs($ägare)->get("/containers/{$pärm->ulid}/history")->assertOk(),
    ));

    expect($rader)->toHaveCount(1)
        ->and($rader[0]['agent'])->toBe(['ulid' => null, 'name' => null]);
});

/*
 * Klart när: agentfiltret i containerns historik ger bara den agentens rader,
 * och talen i sammanställningen följer filtret.
 *
 * Två agenter och en person skriver i samma container. Filtret på den ena
 * agenten ska lämna EN rad — en lista som bara sållade bort personen hade
 * lämnat båda agentraderna kvar och sett rätt ut i en logg med en agent.
 * `perType` räknas ur samma `filtered()` och ska därför summera till 1.
 */
it('agentfiltret ger bara den agentens rader och talen följer', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = agenthistorikKontext();
    $claude = agenthistorikAgent($ägare, $konto, 'Claude');
    $gpt = agenthistorikAgent($ägare, $konto, 'GPT');

    $träff = agenthistorikRad($pärm, $konto, $ägare, $claude, när: now()->subMinutes(3));
    agenthistorikRad($pärm, $konto, $ägare, $gpt, när: now()->subMinutes(2));
    agenthistorikRad($pärm, $konto, $ägare, null, när: now()->subMinute());

    $url = "/containers/{$pärm->ulid}/history?agent={$claude->ulid}";

    $proppar = agenthistorikProps(actingAs($ägare)->get($url)->assertOk());

    $rader = agenthistorikRader($proppar);

    expect(agenthistorikUlids($rader))->toBe([$träff->ulid])
        ->and($proppar['filter']['agent'])->toBe($claude->ulid)
        ->and(agenthistorikTypsumma($proppar['stats']['perType']))->toBe(1);

    // Utan filter räknar talen alla tre, så provet bevisar att filtret
    // sållade och inte att loggen var liten.
    $alla = agenthistorikProps(actingAs($ägare)->get("/containers/{$pärm->ulid}/history")->assertOk());

    expect(agenthistorikRader($alla))->toHaveCount(3)
        ->and(agenthistorikTypsumma($alla['stats']['perType']))->toBe(3)
        ->and($alla['filter']['agent'])->toBeNull();
});

/*
 * Klart när: filtret `agent` verkar på den samlade historiken som på fliken.
 *
 * `filtered()` är samma funktion för båda startpunkterna (issue 271), och
 * provet fäster det genom `/history`: bara agentens rad kommer ut, och
 * `filter.agent` är det värde servern tillämpade.
 */
it('agentfiltret på den samlade historiken ger bara den agentens rader', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = agenthistorikKontext();
    $vinden = Container::factory()->for($konto, 'account')->create(['name' => 'Vinden']);

    $claude = agenthistorikAgent($ägare, $konto, 'Claude');
    $gpt = agenthistorikAgent($ägare, $konto, 'GPT');

    $träff = agenthistorikRad($pärm, $konto, $ägare, $claude, när: now()->subMinutes(3));
    agenthistorikRad($pärm, $konto, $ägare, $gpt, när: now()->subMinutes(2));
    agenthistorikRad($vinden, $konto, $ägare, null, när: now()->subMinute());

    $proppar = agenthistorikProps(
        actingAs($ägare)->get("/history?agent={$claude->ulid}")->assertOk(),
    );

    $rader = agenthistorikRader($proppar);

    expect(agenthistorikUlids($rader))->toBe([$träff->ulid])
        ->and($proppar['filter']['agent'])->toBe($claude->ulid)
        ->and(agenthistorikTypsumma($proppar['stats']['perType']))->toBe(1);
});

/*
 * Klart när: `options.agents` innehåller bara agenter som förekommer i rader
 * läsaren får se, med ägarens namn.
 *
 * Ägaren ser båda agenterna; gästen ser bara sin egen rad och därmed bara
 * agenten som skrev den. En meny som bjöd på den andra agenten hade avslöjat
 * vem mer som skrivit — samma regel som för `options.users`.
 */
it('options.agents innehåller bara agenter i läsbara rader', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = agenthistorikKontext();
    $gäst = agenthistorikGast($pärm, 'Bo');

    $claude = agenthistorikAgent($ägare, $konto, 'Claude');
    $gpt = agenthistorikAgent($ägare, $konto, 'GPT');

    agenthistorikRad($pärm, $konto, $ägare, $claude);
    agenthistorikRad($pärm, $konto, $gäst, $gpt);

    $url = "/containers/{$pärm->ulid}/history";

    $ägarens = agenthistorikProps(actingAs($ägare)->get($url)->assertOk());
    $gästens = agenthistorikProps(actingAs($gäst)->get($url)->assertOk());

    expect(agenthistorikUlids($ägarens['options']['agents']))->toBe([$claude->ulid, $gpt->ulid])
        ->and($ägarens['options']['agents'][0]['owner'])->toBe('Anna')
        ->and(agenthistorikUlids($gästens['options']['agents']))->toBe([$gpt->ulid]);

    // Egen `expect` och inte en kedja: `toContain()` bor i
    // Pest\Mixins\Expectation, som saknar `$not`, och PHPStan faller på en
    // andra `->not` i samma kedja (samma skäl som i HistorikflikTest).
    expect(agenthistorikUlids($gästens['options']['agents']))->not->toContain($claude->ulid);
});

/*
 * Klart när: dashboardens *Recent activity* visar agenten utan en egen
 * ändring — panelen ritar samma `HistoryRow` och läser samma propp.
 */
it('dashboardens senaste aktivitet bär agenten', function () {
    withoutVite();

    [$konto, $ägare, $pärm] = agenthistorikKontext();
    $agent = agenthistorikAgent($ägare, $konto, 'Claude');

    agenthistorikRad($pärm, $konto, $ägare, $agent);

    /** @var list<array<string, mixed>> $event */
    $event = agenthistorikProps(actingAs($ägare)->get('/dashboard')->assertOk())['events'];

    expect($event)->toHaveCount(1)
        ->and($event[0]['agent'])->toBe(['ulid' => $agent->ulid, 'name' => 'Claude']);
});

/*
 * Klart när: antalet frågor för en historiksida med agentrader växer inte med
 * antalet rader.
 *
 * Provet mäter HELA sidan, inte bara actionen: `ListAuditEvents` eager-laddar
 * både `user` och `agent`, och `PresentAuditEvents` slår upp containernamnen i
 * EN fråga. Fler rader med samma agent ska därför kosta exakt lika många
 * frågor. Klockan är frusen, annars kunde `UpdateLastActiveAt` skriva en
 * UPDATE mellan de två mätningarna.
 */
it('frågorna för en historiksida växer inte med antalet agentrader', function () {
    withoutVite();

    Carbon::setTestNow('2026-11-21 12:00:00');

    [$konto, $ägare, $pärm] = agenthistorikKontext();
    $agent = agenthistorikAgent($ägare, $konto, 'Claude');

    agenthistorikRad($pärm, $konto, $ägare, $agent);

    $url = "/containers/{$pärm->ulid}/history";

    // Två värmande anrop: det första skriver `last_active_at`, det andra
    // bevisar att skrivningen är strypt. Först därefter mäts något.
    actingAs($ägare)->get($url)->assertOk();
    actingAs($ägare)->get($url)->assertOk();

    $enRad = agenthistorikFragor(fn () => actingAs($ägare)->get($url)->assertOk());

    foreach (range(2, 6) as $ignorera) {
        agenthistorikRad($pärm, $konto, $ägare, $agent);
    }

    $femRader = agenthistorikFragor(fn () => actingAs($ägare)->get($url)->assertOk());

    expect($femRader)->toBe($enRad);
});

/*
 * Klart när: raden ritar *"{person} via {agent}"* med märkningen *Agent*, och
 * *via a removed agent* när namnet saknas.
 *
 * Källkodsprov av samma skäl som HistorikradIkonTest: raden ritas i
 * webbläsaren. Nycklarna prövas mot katalogen, så en glömd översättning faller
 * här i stället för att synas som sin egen nyckel på skärmen.
 */
it('raden ritar avsändaren med agenten och en agentmärkning', function () {
    $rad = (string) preg_replace('#/\*.*?\*/#s', '', File::get(resource_path('js/components/HistoryRow.vue')));

    expect($rad)->toContain("t('history.row.actor_via_agent'")
        ->toContain("t('history.row.actor_via_removed_agent'")
        ->toContain("t('history.row.agent_badge'")
        ->toContain('UiBadge')
        ->toContain('props.row.agent')
        // Raden utan agent går genom samma gren som förut.
        ->toContain("t('audit.fallback.user')");

    expect(trans('ui.history.row.actor_via_agent', ['user' => 'Anna', 'agent' => 'Claude'], 'en'))
        ->toBe('Anna via Claude')
        ->and(trans('ui.history.row.actor_via_removed_agent', ['user' => 'Anna'], 'en'))
        ->toBe('Anna via a removed agent')
        ->and(trans('ui.history.row.agent_badge', [], 'en'))->toBe('Agent');
});

/*
 * Klart när: filterfältet har ett agentval bredvid *User*.
 *
 * Väljaren ritas ur `options.agents` och bara när listan inte är tom — en meny
 * med bara *Alla* är brus. Etiketten bär agenten och personen den handlar för.
 */
it('filterfältet har ett agentval bredvid User', function () {
    $fältet = (string) preg_replace('#/\*.*?\*/#s', '', File::get(resource_path('js/components/HistoryFilterBar.vue')));

    expect($fältet)->toContain("t('audit.history.filter_agent')")
        ->toContain("t('audit.history.filter_agent_option'")
        ->toContain('options.agents')
        ->toContain('params.agent');

    expect(trans('ui.audit.history.filter_agent', [], 'en'))->toBe('Agent')
        ->and(trans('ui.audit.history.filter_agent_option', ['agent' => 'Claude', 'owner' => 'Anna'], 'en'))
        ->toBe('Claude (Anna)');
});

<?php

use App\Actions\Attachment\ListAccessibleAttachments;
use App\Actions\Inbox\ResolveInbox;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\withoutVite;

/*
 * M28 · testarnas fynd 2026-10-07 (#779 · issue 255:s serversida), se
 * app/Http/Controllers/DocumentController.php,
 * app/Actions/Attachment/ListAccessibleAttachments.php, routes/web.php och
 * resources/js/pages/Documents/Index.vue.
 *
 * Filen bevisar de fem besluten i issuen:
 *
 * 1. **Urvalet** (Beslut 1): filerna i VARJE container användaren når plus
 *    filerna i hennes EGEN inbox, femtio rader per sida. Den egna inboxen
 *    läggs tillbaka efter `listable()` — den syns inte i någon containerlista
 *    ([[ADR-0054 Inboxen]] § 2) — och den SLÅS UPP utan att skapas.
 * 2. **Filtren** (Beslut 2): `container[]` (ULID:er och `inbox`), `kind[]` och
 *    `sort`, ur querysträngen. En ULID utanför den nådda mängden ger en tom
 *    lista och inte ett fel.
 * 3. **Raden** (Beslut 3): AttachmentResource med `item` och `container`
 *    bredvid — och för en inboxfil `in_inbox: true` utan båda: inboxens dolda
 *    container och item lämnar aldrig servern (ADR-0054 § 1).
 * 4. **Sidan** (Beslut 4): propparna `attachments`, `filter`, `containers` och
 *    `kinds`.
 * 5. **Kostnaden** (Beslut 5): frågeantalet är konstant oavsett antal
 *    containrar och filer.
 *
 * **Provet på omfånget är det som håller behörigheten.** En yta som listar
 * filer över HELA kontot är den mest sannolika platsen för ett läckage: ett
 * urval som tappar `ResolveItemScope` ser identiskt ut med EN medlem, och
 * först en gäst med en itemgrant visar skillnaden. Samma sak gäller inboxen —
 * ett personkonto kan ha fler medlemmar (ADR-0054 § 2), och den som listar
 * kontots containrar hade sett sin sambos inbox.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma grepp som DokumentflikTest: sorteringen prövas på `created_at`, och en
 * fast klocka gör raderna jämförbara.
 *
 * Hjälparna har prefixet `globalaDokument` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett personkonto med en medlem i.
 *
 * @return array{0: Account, 1: User}
 */
function globalaDokumentKonto(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * Ett item i containern, med kända `created_by_*`.
 */
function globalaDokumentItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En bilaga på ett item, med känd typ, tid och storlek.
 *
 * `stored_file` skapas explicit och delas aldrig mellan två bilagor: `size`
 * sorterar på dess `byte_size`, och två rader med samma fil hade gjort proven
 * svagare än de ser ut.
 */
function globalaDokumentBilaga(
    Item $item,
    User $uppladdare,
    string $filnamn,
    string $kind = 'document',
    ?Carbon $skapad = null,
    int $byteSize = 1024,
): Attachment {
    $storedFile = StoredFile::factory()->create([
        'byte_size' => $byteSize,
        'mime_type' => $kind === 'image' ? 'image/jpeg' : 'application/pdf',
    ]);

    return Attachment::factory()->for($item, 'item')->create([
        'stored_file_id' => $storedFile->id,
        'filename' => $filnamn,
        'kind' => $kind,
        'uploaded_by_user_id' => $uppladdare->id,
        'billed_account_id' => $item->container->account_id,
        'created_at' => $skapad ?? now(),
    ]);
}

/**
 * En gäst UTANFÖR ägarkontot. Utan $item blir granten container-bred; med ett
 * item blir den item-bred — samma form som på dokumentfliken.
 */
function globalaDokumentGast(Container $container, ?Item $item = null, string $niva = 'read'): User
{
    $gast = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item?->id,
        'grantee_type' => 'user',
        'grantee_id' => $gast->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $gast;
}

/**
 * Sidans adress, med en valfri querysträng (utan inledande `?`).
 */
function globalaDokumentUrl(string $query = ''): string
{
    return $query === '' ? '/documents' : "/documents?{$query}";
}

/**
 * Propparna ur svaret, med den form proven läser.
 *
 * @return array<string, mixed>
 */
function globalaDokumentProps(TestResponse $svar): array
{
    /** @var array<string, mixed> $proppar */
    $proppar = $svar->inertiaProps();

    return $proppar;
}

/**
 * Filnamnen i listan, i den ordning servern skickade dem.
 *
 * @param  array<string, mixed>  $proppar
 * @return list<string>
 */
function globalaDokumentFilnamn(array $proppar): array
{
    /** @var list<array<string, mixed>> $rader */
    $rader = $proppar['attachments']['data'];

    return array_map(static fn (array $rad): string => (string) $rad['filename'], $rader);
}

/*
 * Klart när: listan bär filer ur alla containrar användaren når (Beslut 1).
 *
 * Fyra filer i två containrar — samma konto — svaras ut i EN lista, nyast
 * först, ur en sida om femtio. Att båda containrarna är med ÄR provet: en vy
 * som bara listade den första hade sett riktig ut med EN container.
 */
it('listan bär filer ur alla containrar användaren når', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $verkstad = Container::factory()->for($konto, 'account')->create(['name' => 'Verkstaden']);
    $forrad = Container::factory()->for($konto, 'account')->create(['name' => 'Förrådet']);

    $motor = globalaDokumentItem($verkstad, 'Motorn');
    $hylla = globalaDokumentItem($forrad, 'Hyllan');

    globalaDokumentBilaga($motor, $ägare, 'aldst.pdf', 'document', now()->subMinutes(4));
    globalaDokumentBilaga($hylla, $ägare, 'nast.pdf', 'document', now()->subMinutes(3));
    globalaDokumentBilaga($motor, $ägare, 'bild.jpg', 'image', now()->subMinutes(2));
    globalaDokumentBilaga($hylla, $ägare, 'nyast.pdf', 'document', now()->subMinute());

    $proppar = globalaDokumentProps(actingAs($ägare)->get(globalaDokumentUrl())->assertOk());

    expect(globalaDokumentFilnamn($proppar))->toBe(['nyast.pdf', 'bild.jpg', 'nast.pdf', 'aldst.pdf'])
        ->and($proppar['attachments']['total'])->toBe(4)
        ->and($proppar['attachments']['per_page'])->toBe(ListAccessibleAttachments::PER_PAGE);

    // Raden bär sitt item OCH sin container (Beslut 3) — den globala listan
    // är den enda platsen där filer ur flera containrar möts.
    expect($proppar['attachments']['data'][0]['item']['name'])->toBe('Hyllan')
        ->and($proppar['attachments']['data'][0]['container']['name'])->toBe('Förrådet')
        ->and($proppar['attachments']['data'][0]['in_inbox'])->toBeFalse();

    // Containerväljaren bär båda, med sina antal (Beslut 4), och typantalen
    // räknar samma fyra filer.
    expect(array_column($proppar['containers'], 'ulid'))
        ->toBe([$forrad->ulid, $verkstad->ulid])
        ->and(array_column($proppar['containers'], 'count'))->toBe([2, 2])
        ->and($proppar['kinds'])->toBe(['image' => 1, 'document' => 3, 'other' => 0]);
});

/*
 * Klart när: en container användaren inte når syns inte (Beslut 1).
 *
 * Två konton med var sin container och var sin fil. Ägaren ser sin egen och
 * ingenting av den andres — och den andre ser sin. Att den andre ser sin är
 * den andra halvan: ett urval som råkade filtrera bort för ALLA hade sett ut
 * som ett riktigt omfång i den första kontrollen.
 */
it('en container användaren inte når syns inte', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();
    [$annatKonto, $granne] = globalaDokumentKonto();

    $min = Container::factory()->for($konto, 'account')->create();
    $hennes = Container::factory()->for($annatKonto, 'account')->create();

    globalaDokumentBilaga(globalaDokumentItem($min, 'Motorn'), $ägare, 'min.pdf');
    globalaDokumentBilaga(globalaDokumentItem($hennes, 'Hennes'), $granne, 'hennes.pdf');

    expect(globalaDokumentFilnamn(globalaDokumentProps(
        actingAs($ägare)->get(globalaDokumentUrl())->assertOk(),
    )))->toBe(['min.pdf']);

    expect(globalaDokumentFilnamn(globalaDokumentProps(
        actingAs($granne)->get(globalaDokumentUrl())->assertOk(),
    )))->toBe(['hennes.pdf']);
});

/*
 * Klart när: en omfångsbegränsad medlem ser bara sitt omfångs filer
 * (Beslut 1).
 *
 * Gästen har en ITEM-grant på motorn och når därför motorns fil och ingenting
 * annat — varken värmarens fil i samma container eller filen i en container
 * hon inte har någon grant i. Omfånget är detsamma som på containerns
 * dokumentflik (App\Actions\Access\ResolveItemScope), och ägaren ser allt
 * fyra: samma yta, två svar.
 */
it('en omfångsbegränsad medlem ser bara sitt omfångs filer', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $verkstad = Container::factory()->for($konto, 'account')->create();
    $forrad = Container::factory()->for($konto, 'account')->create();

    $motor = globalaDokumentItem($verkstad, 'Motorn');

    globalaDokumentBilaga($motor, $ägare, 'motormanual.pdf');
    globalaDokumentBilaga(globalaDokumentItem($verkstad, 'Värmaren'), $ägare, 'varmarmanual.pdf');
    globalaDokumentBilaga(globalaDokumentItem($forrad, 'Hyllan'), $ägare, 'hyllan.pdf');

    $gäst = globalaDokumentGast($verkstad, $motor);

    expect(globalaDokumentFilnamn(globalaDokumentProps(
        actingAs($gäst)->get(globalaDokumentUrl())->assertOk(),
    )))->toBe(['motormanual.pdf']);

    expect(globalaDokumentFilnamn(globalaDokumentProps(
        actingAs($ägare)->get(globalaDokumentUrl())->assertOk(),
    )))->toHaveCount(3);
});

/*
 * Klart när: inboxens filer syns för ägaren med in_inbox och utan container
 * (Beslut 1 och 3).
 *
 * Inboxen syns inte i någon containerlista (ADR-0054 § 2), men hennes filer
 * hör hemma i den globala listan. Raden bär `in_inbox: true` och varken
 * `item` eller `container`: båda är dolda (ADR-0054 § 1), och ett namn eller
 * en ULID hade varit en plats användaren inte får se. Containerväljaren bär
 * `inbox` FÖRST (Beslut 4).
 */
it('inboxens filer syns för ägaren med in_inbox och utan container', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $container = Container::factory()->for($konto, 'account')->create();
    globalaDokumentBilaga(globalaDokumentItem($container, 'Motorn'), $ägare, 'motormanual.pdf');

    $inbox = app(ResolveInbox::class)->handle($ägare);
    globalaDokumentBilaga($inbox, $ägare, 'fangad.jpg', 'image', now()->addMinute());

    $proppar = globalaDokumentProps(actingAs($ägare)->get(globalaDokumentUrl())->assertOk());

    expect(globalaDokumentFilnamn($proppar))->toBe(['fangad.jpg', 'motormanual.pdf']);

    $rad = $proppar['attachments']['data'][0];

    expect($rad['in_inbox'])->toBeTrue()
        ->and($rad['container'])->toBeNull()
        ->and($rad['item'])->toBeNull();

    // Inboxen står först i containerväljaren, och bär sitt antal.
    expect($proppar['containers'][0])->toBe(['ulid' => 'inbox', 'count' => 1])
        ->and($proppar['kinds'])->toBe(['image' => 1, 'document' => 1, 'other' => 0]);
});

/*
 * Klart när: en annan användares inbox syns inte (Beslut 1).
 *
 * **Båda är medlemmar i SAMMA personkonto**, och det är provets udd: ett
 * personkonto kan ha fler medlemmar, och den som listar kontots containrar
 * hade sett sin sambos inbox. `Container::scopeListable()` filtrerar bort
 * varje inbox, och den egna läggs tillbaka på `inbox_user_id` — så hon ser
 * sin och inte hans.
 */
it('en annan användares inbox syns inte', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $sambo = User::factory()->create();
    $konto->users()->attach($sambo, ['role' => 'member']);

    $container = Container::factory()->for($konto, 'account')->create();
    globalaDokumentBilaga(globalaDokumentItem($container, 'Motorn'), $ägare, 'motormanual.pdf');

    $hansInbox = app(ResolveInbox::class)->handle($sambo);
    globalaDokumentBilaga($hansInbox, $sambo, 'hans-fangade.jpg', 'image');

    $minInbox = app(ResolveInbox::class)->handle($ägare);
    globalaDokumentBilaga($minInbox, $ägare, 'min-fangade.jpg', 'image');

    expect(globalaDokumentFilnamn(globalaDokumentProps(
        actingAs($ägare)->get(globalaDokumentUrl())->assertOk(),
    )))->toBe(['min-fangade.jpg', 'motormanual.pdf'])
        // Och han ser sin egen inbox OCH den delade containern, men inte
        // hennes inbox. Den sista raden är provets udd: samma container, två
        // svar, och skillnaden är vems inbox filen ligger i.
        ->and(globalaDokumentFilnamn(globalaDokumentProps(
            actingAs($sambo)->get(globalaDokumentUrl())->assertOk(),
        )))->toBe(['hans-fangade.jpg', 'motormanual.pdf']);
});

/*
 * Klart när: sidan skapar ingen inbox (Beslut 1).
 *
 * Att läsa en vy ska inte skriva en container (ADR-0054 § 1): en användare
 * som ännu inte fångat något möts av en tom lista, och raden i `container`
 * med `inbox_user_id` finns inte efter anropet. Provet mäter DATABASEN och
 * inte bara svaret — en sida som skapade inboxen och ändå visade tomt hade
 * sett riktig ut i den första kontrollen.
 */
it('sidan skapar ingen inbox', function () {
    withoutVite();

    [, $ägare] = globalaDokumentKonto();

    $proppar = globalaDokumentProps(actingAs($ägare)->get(globalaDokumentUrl())->assertOk());

    expect(globalaDokumentFilnamn($proppar))->toBe([])
        ->and($proppar['containers'])->toBe([])
        ->and($proppar['kinds'])->toBe(['image' => 0, 'document' => 0, 'other' => 0]);

    expect(DB::table('container')->whereNotNull('inbox_user_id')->count())->toBe(0);
});

/*
 * Klart när: filtret container avgränsar listan (Beslut 2).
 *
 * Två containrar, en ULID i adressen, och bara den containerns fil svaras ut.
 * Filtret läses en gång och följer med som `filter`-propp, så vyn ritar fälten
 * ur svaret och aldrig ur adressen.
 */
it('filtret container avgränsar listan', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $verkstad = Container::factory()->for($konto, 'account')->create();
    $forrad = Container::factory()->for($konto, 'account')->create();

    globalaDokumentBilaga(globalaDokumentItem($verkstad, 'Motorn'), $ägare, 'motormanual.pdf');
    globalaDokumentBilaga(globalaDokumentItem($forrad, 'Hyllan'), $ägare, 'hyllan.pdf');

    $proppar = globalaDokumentProps(actingAs($ägare)->get(
        globalaDokumentUrl('container[]='.$forrad->ulid),
    )->assertOk());

    expect(globalaDokumentFilnamn($proppar))->toBe(['hyllan.pdf'])
        ->and($proppar['filter']['container'])->toBe([$forrad->ulid])
        // Antalen följer inte filtret: båda containrarna står kvar med sitt
        // tal, annars hade väljaren sagt att den ena var tom.
        ->and(array_column($proppar['containers'], 'count'))->toBe([1, 1]);
});

/*
 * Klart när: filtret inbox avgränsar till inboxen (Beslut 2).
 *
 * `container[]=inbox` är ordet och inte en ULID — inboxens container är dold
 * (ADR-0054 § 1) — och listan blir inboxens filer och ingenting annat.
 */
it('filtret inbox avgränsar till inboxen', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $container = Container::factory()->for($konto, 'account')->create();
    globalaDokumentBilaga(globalaDokumentItem($container, 'Motorn'), $ägare, 'motormanual.pdf');

    $inbox = app(ResolveInbox::class)->handle($ägare);
    globalaDokumentBilaga($inbox, $ägare, 'fangad.jpg', 'image');

    $proppar = globalaDokumentProps(actingAs($ägare)->get(
        globalaDokumentUrl('container[]=inbox'),
    )->assertOk());

    expect(globalaDokumentFilnamn($proppar))->toBe(['fangad.jpg'])
        ->and($proppar['filter']['container'])->toBe(['inbox'])
        ->and($proppar['attachments']['data'][0]['in_inbox'])->toBeTrue();
});

/*
 * Klart när: en främmande container i filtret ger en tom lista och inte ett
 * fel (Beslut 2).
 *
 * Filtret smalnar av mot det användaren REDAN ser och kan aldrig bredda det:
 * en ULID ur någon annans konto svarar 200 med en tom lista — inte 403, inte
 * 422 och inte den andres filer. `kind[]=video` och en ULID som inte är en
 * ULID faller bort som "inget filter" och ger hela listan, samma linje som
 * dokumentfliken drar.
 */
it('en främmande container i filtret ger en tom lista och inte ett fel', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();
    [, $granne] = globalaDokumentKonto();

    $min = Container::factory()->for($konto, 'account')->create();
    $hennes = Container::factory()->for($granne->accounts->first(), 'account')->create();

    globalaDokumentBilaga(globalaDokumentItem($min, 'Motorn'), $ägare, 'motormanual.pdf');
    globalaDokumentBilaga(globalaDokumentItem($hennes, 'Hennes'), $granne, 'hennes.pdf');

    $främmande = globalaDokumentProps(actingAs($ägare)->get(
        globalaDokumentUrl('container[]='.$hennes->ulid),
    )->assertOk());

    expect(globalaDokumentFilnamn($främmande))->toBe([]);

    // Ett värde som inte går att läsa är inget filter — listan svarar på den
    // fråga användaren faktiskt ställde.
    $okänd = globalaDokumentProps(actingAs($ägare)->get(
        globalaDokumentUrl('container[]=inte-en-ulid&kind[]=video'),
    )->assertOk());

    expect($okänd['filter']['container'])->toBe([])
        ->and($okänd['filter']['kind'])->toBe([])
        ->and(globalaDokumentFilnamn($okänd))->toBe(['motormanual.pdf']);
});

/*
 * Klart när: raderade filer syns inte (Beslut 1).
 *
 * Tre filer och bara en ska synas: en i papperskorgen, en på ett item i
 * papperskorgen. Att raden ligger KVAR i databasen efter mjukraderingen
 * prövas på annat håll; här är frågan vad listan VISAR.
 */
it('raderade filer syns inte', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $kvar = globalaDokumentItem(Container::factory()->for($konto, 'account')->create(), 'Motorn');
    $slängd = globalaDokumentItem(Container::factory()->for($konto, 'account')->create(), 'Värmaren');

    globalaDokumentBilaga($kvar, $ägare, 'kvar.pdf');

    globalaDokumentBilaga($kvar, $ägare, 'slangd-bilaga.pdf')->delete();
    globalaDokumentBilaga($slängd, $ägare, 'slangt-item.pdf');
    $slängd->delete();

    expect(globalaDokumentFilnamn(globalaDokumentProps(
        actingAs($ägare)->get(globalaDokumentUrl())->assertOk(),
    )))->toBe(['kvar.pdf']);
});

/*
 * Klart när: antalet frågor är konstant (Beslut 5).
 *
 * Sidan mäts två gånger: med en container och en fil, och med sex containrar
 * och sex filer. Antalet frågor ska vara DETSAMMA — det är hela skillnaden
 * mellan en fråga per container och en fråga för alla. Provet mäter LIKHET och
 * inte ett absolut tal, samma teknik som grannfilerna: vad skalet, de delade
 * propparna och grinden kostar i övrigt är inte den här issuen att låsa.
 *
 * En uppvärmningsrequest först: den inloggade användaren ligger kvar i minnet
 * mellan anropen i samma test, så den första mätningen hade annars betalat för
 * laddningar den andra får gratis.
 *
 * **Scoped-instanserna glöms mellan mätningarna.** `ResolveItemScope` är
 * registrerad som `scoped` och memoiserar per request — men testklienten kör
 * `handle()` och aldrig `terminate()`, så utan raden hade den andra mätningen
 * lånat den förstas memon och sett billigare ut än en riktig request. Det är
 * skillnaden mellan att mäta frågekostnaden och att mäta memon.
 */
it('antalet frågor är konstant', function () {
    withoutVite();

    [$konto, $ägare] = globalaDokumentKonto();

    $första = Container::factory()->for($konto, 'account')->create();
    globalaDokumentBilaga(globalaDokumentItem($första, 'Motorn'), $ägare, 'motormanual.pdf');

    actingAs($ägare);
    get(globalaDokumentUrl())->assertOk();

    DB::enableQueryLog();

    /** @return array{0: int, 1: TestResponse} */
    $mät = function (): array {
        app()->forgetScopedInstances();

        DB::flushQueryLog();

        $svar = get(globalaDokumentUrl())->assertOk();

        return [count(DB::getQueryLog()), $svar];
    };

    [$frågorMedEn] = $mät();

    // Fler containrar, items och filer — frågeantalet ska INTE växa.
    foreach (range(1, 5) as $nummer) {
        $container = Container::factory()->for($konto, 'account')->create();

        globalaDokumentBilaga(
            globalaDokumentItem($container, "Item {$nummer}"),
            $ägare,
            "fil-{$nummer}.pdf",
        );
    }

    [$frågorMedSex, $svar] = $mät();

    DB::disableQueryLog();

    expect($svar->inertiaProps('attachments.data'))->toHaveCount(6)
        ->and($frågorMedSex)->toBe($frågorMedEn);
});

<?php

use App\Actions\Attachment\ListContainerAttachments;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\ImageDerivative;
use App\Models\Item;
use App\Models\Plan;
use App\Models\StoredFile;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 178 · Containerns dokumentflik, se
 * app/Http/Controllers/ContainerDocumentController.php,
 * app/Actions/Attachment/ListContainerAttachments.php,
 * App\Actions\Attachment\ListRecentOpens, routes/web.php,
 * resources/js/pages/Containers/Documents.vue,
 * resources/js/components/DocumentFilterBar.vue,
 * resources/js/components/StorageBar.vue och [[ADR-0050 Desktopdesignen]]
 * § 12–15.
 *
 * Filen bevisar de fem besluten i issuen:
 *
 * 1. **Rutten och grinden** (Beslut 1): `containers.documents` svarar, en
 *    främling får 403 och inte en tom lista. Flikens plats i raden prövas i
 *    tests/Feature/Frontend/FlikradTest.php, som äger flikraden.
 * 2. **Listan** (Beslut 2): bilagor på items användaren NÅR, nyast först,
 *    trettio per sida — urvalet är App\Actions\Attachment\
 *    ListContainerAttachments, och proven faller om containerns egen bild,
 *    papperskorgen eller ett item utanför omfånget slinker med.
 * 3. **Senast öppnade** (Beslut 3): användarens EGNA öppningar, ur 177:s
 *    tabell. Provet med två personer är det som håller regeln: en panel som
 *    visade containerns öppningar hade sett likadan ut med EN person.
 * 4. **Lagringsstapeln** (Beslut 4): containerns konto för dess medlem,
 *    gästens eget för en gäst, och bara förbrukningen när taket inte finns.
 *    Det sista provet är det som håller `null`-grenen: utan ett tak att fylla
 *    ritas ingen stapel, och en stapel mot ett påhittat tak hade varit ett
 *    mätetal ingen kvot ligger bakom.
 * 5. **Lägg till dokument** (Beslut 5): itemväljaren är de items användaren
 *    får SKAPA på, prövade mot `ItemPolicy::create()`.
 *
 * **Det som INTE prövas här** är det som kräver en webbläsare: att sidan ser
 * ut som `docs/Design/dokument.png`, att listan och rutnätet byter plats med
 * `?view=grid`, och att filterfältet känns rätt i handen. `?view=grid` är ett
 * KLIENTLÄGE och ändrar ingenting i svaret — samma rader svaras ut oavsett —
 * så det finns inget på serversidan att pröva; träffytorna och sidtitelns
 * regler ägs av GenomgangTest, som äger dem för varje sida.
 *
 * **Klockan pinnas till mitt på dagen UTC** ([[ADR-0044 Användarens dag]]),
 * samma grepp som ContainerKostnadsflikTest: servern går i UTC och användaren
 * i Europe/Stockholm, och datumfiltrets dygnsgräns prövas genom att lägga en
 * bilaga strax innanför och en strax utanför den.
 *
 * Hjälparna har prefixet `dokumentflik` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett ägarkonto med en medlem i, och en container under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function dokumentflikKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * Ett item i containern, med kända `created_by_*`.
 */
function dokumentflikItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => $namn,
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En bilaga på ett item, med känd typ, uppladdare, tid och storlek.
 *
 * `stored_file` skapas explicit och delas aldrig mellan två bilagor: `size`
 * sorterar på dess `byte_size`, och två rader med samma fil hade gjort
 * proven svagare än de ser ut.
 */
function dokumentflikBilaga(
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
 * Containerns EGEN bild — en bilaga med `container_id` och `item_id = NULL`
 * ([[ADR-0047 Containerns bild]]).
 */
function dokumentflikOmslag(Container $container): Attachment
{
    return Attachment::factory()->create([
        'item_id' => null,
        'container_id' => $container->id,
        'kind' => 'image',
        'filename' => 'omslag.jpg',
        'billed_account_id' => $container->account_id,
    ]);
}

/**
 * En gäst UTANFÖR ägarkontot. Utan $item blir granten container-bred; med ett
 * item blir den item-bred.
 */
function dokumentflikGast(Container $container, ?Item $item = null, string $niva = 'read'): User
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
 * Flikens adress, med en valfri querysträng (utan inledande `?`).
 */
function dokumentflikUrl(Container $container, string $query = ''): string
{
    $url = "/containers/{$container->ulid}/documents";

    return $query === '' ? $url : "{$url}?{$query}";
}

/**
 * Propparna ur svaret, med den form proven läser.
 *
 * @return array<string, mixed>
 */
function dokumentflikProps(TestResponse $svar): array
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
function dokumentflikFilnamn(array $proppar): array
{
    /** @var list<array<string, mixed>> $rader */
    $rader = $proppar['attachments']['data'];

    return array_map(static fn (array $rad): string => (string) $rad['filename'], $rader);
}

/**
 * Lagringsräknaren för ett konto.
 */
function dokumentflikRaknare(Account $konto, int $storageBytes): void
{
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => $storageBytes,
    ]);
}

/*
 * Klart när: listan visar bilagor på items användaren når, nyast först,
 * trettio per sida (Beslut 2).
 *
 * Tre saker i ett prov, för de hänger ihop: urvalet, ordningen och
 * sidstorleken. Trettiotvå bilagor över två items — fler än en sida — och
 * `created_at` stigande så att "nyast först" går att läsa ur svaret. Provet
 * följer också med till sida två, för en paginator som svarade med allt på
 * sidan ett hade sett rätt ut i den första kontrollen.
 */
it('listan visar bilagor på items användaren når, nyast först, trettio per sida', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    // Bilaga 1 är äldst och 32 nyast: namnens ordning i svaret ska vara den
    // omvända.
    foreach (range(1, 32) as $nummer) {
        $item = $nummer % 2 === 0 ? $motor : $varmare;

        dokumentflikBilaga(
            $item,
            $ägare,
            sprintf('dokument-%02d.pdf', $nummer),
            skapad: now()->subMinutes(32 - $nummer),
        );
    }

    $första = actingAs($ägare)->get(dokumentflikUrl($container))->assertOk();

    $proppar = dokumentflikProps($första);

    expect($proppar['attachments']['total'])->toBe(32)
        ->and($proppar['attachments']['current_page'])->toBe(1)
        ->and($proppar['attachments']['last_page'])->toBe(2)
        ->and($proppar['attachments']['per_page'])->toBe(ListContainerAttachments::PER_PAGE)
        ->and($proppar['attachments']['data'])->toHaveCount(30);

    expect(dokumentflikFilnamn($proppar))->toBe(
        collect(range(32, 3))->map(fn (int $n): string => sprintf('dokument-%02d.pdf', $n))->values()->all(),
    );

    // Raden bär sitt item, så listan kan länka till itemets bilageflik.
    expect($proppar['attachments']['data'][0]['item']['name'])->toBe('Motorn');

    $andra = actingAs($ägare)->get(dokumentflikUrl($container, 'page=2'))->assertOk();

    expect(dokumentflikFilnamn(dokumentflikProps($andra)))->toBe([
        'dokument-02.pdf',
        'dokument-01.pdf',
    ]);
});

/*
 * Klart när: en bilaga på ett item utanför omfånget visas inte (Beslut 2).
 *
 * Gästen har en ITEM-grant på motorn och når därför motorns bilaga och
 * ingenting annat. Ägaren ser båda — samma container, samma sida, två svar.
 * Att ägaren ser båda är den andra halvan av provet: ett urval som råkade
 * filtrera bort för ALLA hade sett ut som ett riktigt omfång i den första
 * kontrollen.
 */
it('en bilaga på ett item utanför omfånget visas inte', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    dokumentflikBilaga($motor, $ägare, 'motormanual.pdf', skapad: now()->subMinute());
    dokumentflikBilaga($varmare, $ägare, 'varmarmanual.pdf');

    $gäst = dokumentflikGast($container, $motor);

    expect(dokumentflikFilnamn(dokumentflikProps(
        actingAs($gäst)->get(dokumentflikUrl($container))->assertOk(),
    )))->toBe(['motormanual.pdf']);

    expect(dokumentflikFilnamn(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )))->toBe(['varmarmanual.pdf', 'motormanual.pdf']);
});

/*
 * Klart när: en bilaga i papperskorgen eller på ett item i papperskorgen
 * visas inte (Beslut 2).
 *
 * Tre bilagor och bara en ska synas. Att raden ligger KVAR i databasen efter
 * mjukraderingen prövas i BilagelistaTest; här är frågan vad listan VISAR.
 */
it('en bilaga i papperskorgen eller på ett item i papperskorgen visas inte', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $kvar = dokumentflikItem($container, 'Motorn');
    $slängd = dokumentflikItem($container, 'Värmaren');

    dokumentflikBilaga($kvar, $ägare, 'kvar.pdf');

    $iPapperskorgen = dokumentflikBilaga($kvar, $ägare, 'slangd-bilaga.pdf');
    $iPapperskorgen->delete();

    dokumentflikBilaga($slängd, $ägare, 'slangt-item.pdf');
    $slängd->delete();

    expect(dokumentflikFilnamn(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )))->toBe(['kvar.pdf']);
});

/*
 * Klart när: containerns egen bild visas inte (Beslut 2).
 *
 * Bilden sitter på `container_id` med `item_id = NULL`
 * ([[ADR-0047 Containerns bild]]), och listan frågar genom item-ledet — men
 * proven fäster det vid listan och inte vid en slutledning: en `whereHas` som
 * någon byter mot en join hade släppt in den, och det hade synts som en rad
 * utan item.
 */
it('containerns egen bild visas inte', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    dokumentflikBilaga($motor, $ägare, 'motormanual.pdf');
    dokumentflikOmslag($container);

    $proppar = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl($container))->assertOk());

    expect(dokumentflikFilnamn($proppar))->toBe(['motormanual.pdf']);

    // Och den ligger kvar i databasen: urvalet är listans och inte en
    // radering.
    expect(DB::table('attachment')->whereNull('item_id')->count())->toBe(1);
});

/*
 * Klart när: filtren på typ, item, uppladdare och datum kan kombineras
 * (Beslut 2).
 *
 * Fyra filter i EN fråga, och träfflistan är den enda rad som uppfyller alla
 * fyra. Proven runt omkring är de som gör det till ett kombinationsprov: en
 * rad för varje enskilt villkor som INTE ska med, så att ett filter som
 * tystnade hade gett fler rader och ett filter som överskred hade gett färre.
 *
 * Datumet är dygnsgränsen i användarens tidszon (Europe/Stockholm, +02:00 i
 * september): `från` och `till` är samma dag, och den rad som ligger kvar
 * skapades mitt på dagen. Raderna utanför ligger ett dygn bort på var sida.
 */
it('filtren på typ, item, uppladdare och datum kan kombineras', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    $annan = User::factory()->create();

    $träff = dokumentflikBilaga($motor, $ägare, 'traff.pdf', 'document', now()->subDay());
    // Fel typ, fel item, fel uppladdare och fel dygn — en rad var.
    dokumentflikBilaga($motor, $ägare, 'fel-typ.pdf', 'image', now()->subDay());
    dokumentflikBilaga($varmare, $ägare, 'fel-item.pdf', 'document', now()->subDay());
    dokumentflikBilaga($motor, $annan, 'fel-uppladdare.pdf', 'document', now()->subDay());
    dokumentflikBilaga($motor, $ägare, 'fel-dygn.pdf', 'document', now()->subDays(3));

    $dygnet = now()->subDay()->toDateString();

    $proppar = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl($container, http_build_query([
        'kind' => 'document',
        'item' => $motor->ulid,
        'uploader' => $ägare->ulid,
        'from' => $dygnet,
        'to' => $dygnet,
    ])))->assertOk());

    expect(dokumentflikFilnamn($proppar))->toBe(['traff.pdf']);

    // Filtret som servern tillämpade följer med vyn, så fälten kan ritas ur
    // svaret i stället för ur adressen. Sedan issue 688 är `kind`, `item` och
    // `uploader` LISTOR (Beslut 4): det skalära värdet i adressen har
    // normaliserats till en lista med ett element, och `q` står med som null.
    expect($proppar['filter'])->toBe([
        'q' => null,
        'kind' => ['document'],
        'item' => [$motor->ulid],
        'uploader' => [$ägare->ulid],
        'from' => $dygnet,
        'to' => $dygnet,
        'sort' => 'newest',
    ]);

    expect($proppar['attachments']['data'][0]['ulid'])->toBe($träff->ulid);
});

/*
 * Klart när: uppladdarfiltret erbjuder bara uppladdare av bilagor användaren
 * ser (Beslut 2).
 *
 * Tre personer laddar upp: ägaren på båda itemen, en andre på det item gästen
 * INTE når, och en tredje vars enda bilaga ligger i papperskorgen. Gästens
 * väljare ska bara bära ägaren — listan är en spegling av träfflistan och inte
 * av containerns medlemmar.
 */
it('uppladdarfiltret erbjuder bara uppladdare av bilagor användaren ser', function () {
    withoutVite();

    [$konto, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    $inne = User::factory()->create();
    $konto->users()->attach($inne, ['role' => 'member']);

    $utanför = User::factory()->create();
    $borttagen = User::factory()->create();

    dokumentflikBilaga($motor, $ägare, 'motormanual.pdf');
    dokumentflikBilaga($varmare, $ägare, 'varmarmanual.pdf');
    dokumentflikBilaga($varmare, $inne, 'inne-i-varmaren.pdf');
    dokumentflikBilaga($varmare, $utanför, 'utanfor.pdf');

    $slängd = dokumentflikBilaga($motor, $borttagen, 'slangd.pdf');
    $slängd->delete();

    $gäst = dokumentflikGast($container, $motor);

    $uppladdare = dokumentflikProps(
        actingAs($gäst)->get(dokumentflikUrl($container))->assertOk(),
    )['filterOptions']['uploaders'];

    expect(array_column($uppladdare, 'ulid'))->toBe([$ägare->ulid]);

    // Ägaren ser alla fyra, sorterade på namn — och den borttagna bilagans
    // uppladdare är inte en av dem.
    $allas = dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )['filterOptions']['uploaders'];

    expect(array_column($allas, 'ulid'))->not->toContain($borttagen->ulid)
        ->and($allas)->toHaveCount(3);
});

/*
 * Klart när: sorteringen på namn och storlek fungerar (Beslut 2).
 *
 * `name` är filnamnet stigande och `size` är bytena fallande — störst först,
 * den fråga en lagringsyta ställer. `stored_file.byte_size` bor i en annan
 * tabell än bilagan, så `size` är den gren som kräver en join; provet fäster
 * att den sorten faktiskt läser bytena och inte faller tillbaka på förvalet.
 */
it('sorteringen på namn och storlek fungerar', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');

    dokumentflikBilaga($motor, $ägare, 'c-manual.pdf', byteSize: 300);
    dokumentflikBilaga($motor, $ägare, 'a-manual.pdf', byteSize: 900);
    dokumentflikBilaga($motor, $ägare, 'b-manual.pdf', byteSize: 100);

    expect(dokumentflikFilnamn(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container, 'sort=name'))->assertOk(),
    )))->toBe(['a-manual.pdf', 'b-manual.pdf', 'c-manual.pdf']);

    expect(dokumentflikFilnamn(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container, 'sort=size'))->assertOk(),
    )))->toBe(['a-manual.pdf', 'c-manual.pdf', 'b-manual.pdf']);

    // `oldest` är samma radmängd vänd, och förvalet är `newest`.
    expect(dokumentflikFilnamn(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container, 'sort=oldest'))->assertOk(),
    )))->toBe(['c-manual.pdf', 'a-manual.pdf', 'b-manual.pdf']);

    expect(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container, 'sort=okand'))->assertOk(),
    )['filter']['sort'])->toBe('newest');
});

/*
 * Klart när: Senast öppnade visar bara användarens egna öppningar (Beslut 3).
 *
 * Två personer har öppnat var sin fil i samma container. Panelen är
 * användarens egen, och provet är det som håller regeln: med EN person hade en
 * panel som visade hela containerns öppningar sett identisk ut.
 *
 * Raderna skrivs direkt i tabellen och inte genom leveransrutten — 177 äger
 * skrivningen (SenastOppnadeTest), och det här provet frågar bara vad fliken
 * LÄSER.
 */
it('Senast öppnade visar bara användarens egna öppningar', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');

    $min = dokumentflikBilaga($motor, $ägare, 'min.pdf');
    $hennes = dokumentflikBilaga($motor, $ägare, 'hennes.pdf');

    $kollega = User::factory()->create();

    DB::table('attachment_open')->insert([
        [
            'user_id' => $ägare->id,
            'attachment_id' => $min->id,
            'opened_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'user_id' => $kollega->id,
            'attachment_id' => $hennes->id,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rader = dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )['recentOpens'];

    expect(array_column($rader, 'ulid'))->toBe([$min->ulid])
        ->and($rader[0]['filename'])->toBe('min.pdf')
        ->and($rader[0]['item']['name'])->toBe('Motorn');

    // Och listan är orörd: båda bilagorna står i den. Panelen är en glimt av
    // öppningarna, inte ett filter på listan.
    expect(dokumentflikFilnamn(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )))->toHaveCount(2);
});

/*
 * Klart när: Senast öppnade bär hasThumb ur bilagans derivat (Beslut 5).
 *
 * En öppnad bild MED `thumb`-derivat ger `true`, en pdf utan derivat `false`.
 * Vyn ritar miniatyren ur `hasThumb` och aldrig ur en gissning: `?variant=
 * thumb` mot en bilaga utan derivat svarar 404 (issue 61b § Beslut 1), och ett
 * `true` för pdf:en hade blivit en trasig bild i kortet. De två raderna i
 * SAMMA svar är det som håller regeln — ett `hasThumb` som alltid var sant
 * hade sett riktigt ut med bara bilden.
 *
 * Derivaten hänger på `stored_file` och inte på bilagan, så provet fäster
 * läsningen vid rätt led: en `derivatives`-fråga ställd mot bilagan hade gett
 * tomt för båda, alltså `false` för bilden och ett prov som föll.
 */
it('Senast öppnade bär hasThumb ur bilagans derivat', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');

    $bild = dokumentflikBilaga($motor, $ägare, 'bild.jpg', 'image');
    ImageDerivative::factory()->create([
        'stored_file_id' => $bild->stored_file_id,
        'variant' => 'thumb',
    ]);

    $pdf = dokumentflikBilaga($motor, $ägare, 'manual.pdf');

    DB::table('attachment_open')->insert([
        [
            'user_id' => $ägare->id,
            'attachment_id' => $bild->id,
            'opened_at' => now()->subMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'user_id' => $ägare->id,
            'attachment_id' => $pdf->id,
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rader = collect(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )['recentOpens']);

    expect($rader->firstWhere('ulid', $bild->ulid)['hasThumb'])->toBeTrue()
        ->and($rader->firstWhere('ulid', $pdf->ulid)['hasThumb'])->toBeFalse();
});

/*
 * Klart när: lagringsstapeln visar containerns konto för dess medlem
 * (Beslut 4).
 *
 * Medlemmen i ägarkontot laddar upp på ägarkontot, och stapeln visar
 * ägarkontots förbrukning mot dess tak — samma två läsningar som
 * Entitlements::assertStorageWithinLimit() gör. Provet fäster talen vid
 * räknaren och planen och inte vid ett formaterat tal: en stapel som ritade
 * något annat än det uppladdningen nekas för är två svar på samma fråga.
 *
 * Personen har ett EGET konto också, med en annan förbrukning. Att det kontot
 * inte är det som visas är provets udd: förvalet är containerns konto när
 * användaren är medlem i det, precis som i ItemAttachmentSection.vue.
 */
it('lagringsstapeln visar containerns konto för dess medlem', function () {
    withoutVite();

    [$konto, $ägare, $container] = dokumentflikKontext();

    $eget = Account::factory()->create();
    $eget->users()->attach($ägare, ['role' => 'owner']);

    dokumentflikRaknare($konto, 2 * 1024 * 1024 * 1024);
    dokumentflikRaknare($eget, 500);

    $lagring = dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )['storage'];

    expect($lagring['account']['ulid'])->toBe($konto->ulid)
        ->and($lagring['usedBytes'])->toBe(2 * 1024 * 1024 * 1024)
        ->and($lagring['limitBytes'])->toBe($konto->planLimit('storage_bytes'))
        // 2 GB av free-planens 1 GB: kvoten är överskriden, och stapeln är
        // full och inte längre än rutan.
        ->and($lagring['percent'])->toBe(100);

    // Och procenten är serverns tal, inte bara en klämning: 100 MB av 1 GB är
    // 9 %, och vyn ritar bredden ur det. Räckvidden prövas för att en
    // `percent` som alltid var 0 eller 100 hade sett riktig ut ovan.
    UsageCounter::query()->where('account_id', $konto->id)->update([
        'storage_bytes' => 100 * 1024 * 1024,
    ]);

    expect(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )['storage']['percent'])->toBe(9);
});

/*
 * Klart när: lagringsstapeln visar gästens eget konto för en gäst (Beslut 4).
 *
 * Gästen är inte medlem i containerns konto, och en uppladdning hon gör
 * debiteras hennes EGET konto (§ 15). Stapeln visar därför hennes konto och
 * hennes tak — och aldrig ägarkontots förbrukning, som är någon annans.
 *
 * Provet är också det som håller "ingen ser någon annans förbrukning": de två
 * talen skiljer sig åt i svaret, och det är gästens som står där.
 */
it('lagringsstapeln visar gästens eget konto för en gäst', function () {
    withoutVite();

    [$konto, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    dokumentflikBilaga($motor, $ägare, 'motormanual.pdf');

    dokumentflikRaknare($konto, 900 * 1024 * 1024);

    $gäst = dokumentflikGast($container);

    // Gästen har ett EGET konto — i drift skapas det vid registreringen.
    $eget = Account::factory()->create();
    $eget->users()->attach($gäst, ['role' => 'owner']);

    dokumentflikRaknare($eget, 7);

    $lagring = dokumentflikProps(
        actingAs($gäst)->get(dokumentflikUrl($container))->assertOk(),
    )['storage'];

    expect($lagring['account']['ulid'])->toBe($eget->ulid)
        ->and($lagring['usedBytes'])->toBe(7)
        ->and($lagring['limitBytes'])->toBe($eget->planLimit('storage_bytes'))
        ->and($lagring['usedBytes'])->not->toBe(900 * 1024 * 1024);
});

/*
 * Klart när: lagringsstapeln för ett obegränsat tak visar bara förbrukningen
 * (Beslut 4).
 *
 * `planLimit('storage_bytes')` svarar `null` för ett tak som inte finns, och
 * då finns det ingenting att fylla: `limitBytes` och `percent` är båda null,
 * och vyn ritar meningen om förbrukningen och ingen stapel. Ett `percent` mot
 * ett påhittat tak hade varit ett mätetal ingen kvot ligger bakom.
 */
it('lagringsstapeln för ett obegränsat tak visar bara förbrukningen', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $plan = Plan::factory()->create([
        'limits' => array_merge(
            Plan::query()->where('code', 'free')->firstOrFail()->limits,
            ['storage_bytes' => null],
        ),
    ]);

    Subscription::factory()->for($container->account)->for($plan)->create();

    dokumentflikRaknare($container->account, 3 * 1024 * 1024);

    $lagring = dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container))->assertOk(),
    )['storage'];

    expect($lagring['usedBytes'])->toBe(3 * 1024 * 1024)
        ->and($lagring['limitBytes'])->toBeNull()
        ->and($lagring['percent'])->toBeNull();
});

/*
 * Klart när: Lägg till dokument erbjuder bara items användaren får skapa på
 * (Beslut 5).
 *
 * Ägaren får skapa på alla containerns items. En `read`-gäst når sina items
 * men får inte skapa på dem — `ItemPolicy::create()` är samma grind som
 * `POST …/items/{item}/attachments` prövar — och hennes lista är därför tom,
 * vilket är vad vyn ritar som ingen knapp alls.
 *
 * Itemväljaren bakom FILTRET är en annan lista: den är de items hon NÅR, och
 * den är inte tom för gästen. De två får inte byta plats, och provet fäster
 * skillnaden.
 */
it('Lägg till dokument erbjuder bara items användaren får skapa på', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    $ägarens = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl($container))->assertOk());

    expect(array_column($ägarens['items'], 'ulid'))->toBe([$motor->ulid, $varmare->ulid]);

    $gäst = dokumentflikGast($container, $motor);

    $gästens = dokumentflikProps(actingAs($gäst)->get(dokumentflikUrl($container))->assertOk());

    expect($gästens['items'])->toBe([])
        // Men hon NÅR itemet, och filterväljaren bär det.
        ->and(array_column($gästens['filterOptions']['items'], 'ulid'))->toBe([$motor->ulid]);
});

/*
 * Klart när: en främling nekas fliken (Beslut 1).
 *
 * Grinden är `view` på containern, som varje annan av containerns flikar: 403
 * och inte en tom lista — en tom lista hade sagt "inga dokument" om en
 * container hon inte når (ContainerHistoryController § docblock).
 */
it('en främling nekas fliken', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    dokumentflikBilaga($motor, $ägare, 'motormanual.pdf');

    actingAs(User::factory()->create())
        ->get(dokumentflikUrl($container))
        ->assertForbidden();
});

/*
 * Fliken läser genom actions och ritar dem i sin egen form (Beslut 2–4).
 *
 * Ett litet prov med flera saker: rutten svarar med rätt komponent, `variants`
 * byggs ur bilagans derivat och är tom för en bilaga utan dem, och
 * `inlineEnabled` följer med så att rutnätet kan avgöra om en miniatyr alls
 * får ritas. Utan det sista hade vyn ritat en `<img>` mot en bilaga utan
 * derivat, alltså en trasig bild (issue 19a § Beslut 5).
 */
it('ritar raden ur resursen med sitt item och sina varianter', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');

    $medThumb = dokumentflikBilaga($motor, $ägare, 'bild.jpg', 'image');
    ImageDerivative::factory()->create([
        'stored_file_id' => $medThumb->stored_file_id,
        'variant' => 'thumb',
    ]);

    $utanThumb = dokumentflikBilaga($motor, $ägare, 'manual.pdf');

    $proppar = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl($container))->assertOk());

    expect($proppar['variants'][$medThumb->ulid])->toBe(['thumb'])
        ->and($proppar['variants'][$utanThumb->ulid])->toBe([])
        ->and($proppar['inlineEnabled'])->toBeBool();

    $rad = $proppar['attachments']['data'][0];

    expect($rad)->toHaveKeys(['ulid', 'filename', 'kind', 'mime_type', 'byte_size', 'item'])
        // Löpnumret och de främmande nycklarna lämnar aldrig svaret
        // (AttachmentResource § docblock).
        ->and($rad)->not->toHaveKeys(['id', 'item_id', 'stored_file_id', 'uploaded_by_user_id']);
});

/*
 * ---------------------------------------------------------------------------
 * Issue 688 · Filtergrupperna, sökningen och antalen.
 *
 * Filtret tar flera värden per grupp (Beslut 1 och 2), `q` söker i filnamnet
 * (Beslut 3), och `filterOptions` bär antalen (Beslut 5 och 6). Träfflistan
 * prövas som filnamn och antalen som tal — en siffra som råkade bli rätt för
 * EN rad hade sett riktig ut i en svag kontroll.
 * ---------------------------------------------------------------------------
 */

/*
 * Klart när: flera värden i en grupp ger träffar av vart och ett (Beslut 2).
 *
 * Två typer i samma grupp är en ELLER: bilden OCH pdf:en svaras ut medan
 * `other` faller bort. Två items likaså. Ett filter som blev ett OCH inom
 * gruppen hade gett noll rader — den som skriver `kind[]=image&kind[]=document`
 * vill se BÅDA slagen, inte bilagor som är både bild och pdf (vilket ingen är).
 */
it('flera värden i en grupp ger träffar av vart och ett', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    dokumentflikBilaga($motor, $ägare, 'bild.jpg', 'image', now()->subMinutes(4));
    dokumentflikBilaga($motor, $ägare, 'manual.pdf', 'document', now()->subMinutes(3));
    dokumentflikBilaga($motor, $ägare, 'anteckning.txt', 'other', now()->subMinutes(2));
    dokumentflikBilaga($varmare, $ägare, 'varmarmanual.pdf', 'document', now()->subMinute());

    $typer = dokumentflikProps(actingAs($ägare)->get(
        dokumentflikUrl($container, 'kind[]=image&kind[]=document'),
    )->assertOk());

    // Bilden och pdf:en, nyast först, och `other` är borta.
    expect(dokumentflikFilnamn($typer))->toBe(['varmarmanual.pdf', 'manual.pdf', 'bild.jpg'])
        // Och listan normaliseras i den FASTA ordningen image, document,
        // other — inte den inskickade.
        ->and($typer['filter']['kind'])->toBe(['image', 'document']);

    $items = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl(
        $container,
        "item[]={$motor->ulid}&item[]={$varmare->ulid}",
    ))->assertOk());

    expect(dokumentflikFilnamn($items))->toBe([
        'varmarmanual.pdf',
        'anteckning.txt',
        'manual.pdf',
        'bild.jpg',
    ])->and($items['filter']['item'])->toBe([$motor->ulid, $varmare->ulid]);
});

/*
 * Klart när: grupperna kombineras med och (Beslut 2).
 *
 * `kind[]=document&item[]=<motor>` är motorns dokument och ingenting annat:
 * motorns bild faller på typen och värmarens pdf på itemet. Ett gruppfilter
 * som tystnat hade gett tre rader, ett som överskridit noll.
 */
it('grupperna kombineras med och', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    dokumentflikBilaga($motor, $ägare, 'motormanual.pdf', 'document', now()->subMinutes(3));
    dokumentflikBilaga($motor, $ägare, 'motorbild.jpg', 'image', now()->subMinutes(2));
    dokumentflikBilaga($varmare, $ägare, 'varmarmanual.pdf', 'document', now()->subMinute());

    expect(dokumentflikFilnamn(dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl(
        $container,
        "kind[]=document&item[]={$motor->ulid}",
    ))->assertOk())))->toBe(['motormanual.pdf']);
});

/*
 * Klart när: ett skalärt värde och ett okänt värde läses som i dag
 * (Beslut 1).
 *
 * Den gamla adressen `kind=document` ska fungera oförändrat — den
 * normaliseras till en lista med ett element — och ett värde som inte går att
 * läsa är inget filter: varken en typ utanför de tre eller en sträng som inte
 * är en ULID. Listan svarar då på frågan användaren faktiskt ställde (ingen).
 */
it('ett skalärt värde och ett okänt värde läses som i dag', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');

    dokumentflikBilaga($motor, $ägare, 'bild.jpg', 'image', now()->subMinute());
    dokumentflikBilaga($motor, $ägare, 'manual.pdf');

    $skalär = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl($container, 'kind=document'))->assertOk());

    expect($skalär['filter']['kind'])->toBe(['document'])
        ->and(dokumentflikFilnamn($skalär))->toBe(['manual.pdf']);

    $okänd = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl(
        $container,
        'kind[]=video&item[]=inte-en-ulid',
    ))->assertOk());

    expect($okänd['filter']['kind'])->toBe([])
        ->and($okänd['filter']['item'])->toBe([])
        ->and(dokumentflikFilnamn($okänd))->toBe(['manual.pdf', 'bild.jpg']);
});

/*
 * Klart när: sökningen matchar filnamnet och tar procent och understreck
 * bokstavligt (Beslut 3).
 *
 * `%` och `_` är LIKE:s jokertecken, och ett oescapad `%` hade låtit `q=50%`
 * träffa `500.pdf`. Escapetecknet är `!` och inte backslash: `'\\'` är ett
 * tecken i MariaDB men två i sqlite, och sviten kör sqlite. Provet fäster båda
 * halvorna — att `50%.pdf` träffas ÄR beviset för att jokertecknet är
 * bokstavligt, för ett `LIKE` utan escape hade gett samma svar för båda.
 */
it('sökningen matchar filnamnet och tar procent och understreck bokstavligt', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');

    dokumentflikBilaga($motor, $ägare, 'Motormanual.pdf', 'document', now()->subMinutes(5));
    dokumentflikBilaga($motor, $ägare, '500.pdf', 'document', now()->subMinutes(4));
    dokumentflikBilaga($motor, $ägare, '50%.pdf', 'document', now()->subMinutes(3));
    dokumentflikBilaga($motor, $ägare, 'axb.pdf', 'document', now()->subMinutes(2));
    dokumentflikBilaga($motor, $ägare, 'a_b.pdf', 'document', now()->subMinute());

    $sök = fn (string $term): array => dokumentflikFilnamn(dokumentflikProps(
        actingAs($ägare)->get(dokumentflikUrl($container, 'q='.rawurlencode($term)))->assertOk(),
    ));

    // Filnamnet matchas utan hänsyn till versaler.
    expect($sök('manual'))->toBe(['Motormanual.pdf']);

    // `%` är bokstavligt: procenttecknet träffas, `500.pdf` gör det inte.
    expect($sök('50%'))->toBe(['50%.pdf']);

    // `_` är bokstavligt: understrecket träffas, `axb.pdf` gör det inte.
    expect($sök('a_b'))->toBe(['a_b.pdf']);

    expect(dokumentflikProps(actingAs($ägare)->get(
        dokumentflikUrl($container, 'q=manual'),
    )->assertOk())['filter']['q'])->toBe('manual');
});

/*
 * Klart när: räknar bilagorna per typ, item och uppladdare inom omfånget
 * (Beslut 5 och 6).
 *
 * Gästen har en item-grant på motorn och når därför bara motorns bilagor.
 * Antalen räknas över SAMMA urval som listan: tre bilagor, och värmarens
 * uppladdare finns varken i listan eller i siffrorna. Uppladdarna ligger i
 * namnordning och namnen är slumpade, så provet slår upp dem på ULID.
 */
it('räknar bilagorna per typ, item och uppladdare inom omfånget', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');
    $varmare = dokumentflikItem($container, 'Värmaren');

    $grannen = User::factory()->create();
    $varmarens = User::factory()->create();

    dokumentflikBilaga($motor, $ägare, 'motorbild.jpg', 'image');
    dokumentflikBilaga($motor, $ägare, 'motormanual.pdf', 'document');
    dokumentflikBilaga($motor, $grannen, 'grannens.pdf', 'document');
    dokumentflikBilaga($varmare, $varmarens, 'varmarmanual.pdf', 'document');

    $gäst = dokumentflikGast($container, $motor);

    $alternativ = dokumentflikProps(actingAs($gäst)->get(dokumentflikUrl($container))->assertOk())['filterOptions'];

    expect($alternativ['total'])->toBe(3)
        ->and($alternativ['kinds'])->toBe(['image' => 1, 'document' => 2, 'other' => 0]);

    // Gästen når bara motorn, och itemet bär sina tre bilagor.
    expect($alternativ['items'])->toBe([
        ['ulid' => $motor->ulid, 'name' => 'Motorn', 'count' => 3],
    ]);

    $uppladdare = collect($alternativ['uploaders'])->keyBy('ulid');

    expect($uppladdare)->toHaveCount(2)
        ->and($uppladdare[$ägare->ulid]['count'])->toBe(2)
        ->and($uppladdare[$grannen->ulid]['count'])->toBe(1)
        // Värmarens uppladdare laddade bara upp där gästen inte når.
        ->and($uppladdare->has($varmarens->ulid))->toBeFalse();
});

/*
 * Klart när: antalen följer inte filtret (Beslut 5).
 *
 * Siffran bredvid *Image* är alltid antalet bilder användaren når — inte
 * antalet bilder i träfflistan. Ett filter som räknade om sig självt hade visat
 * noll för varje val utom det valda, och en väljare där alla andra alternativ
 * står på noll är en väljare som säger att de är tomma.
 */
it('antalen följer inte filtret', function () {
    withoutVite();

    [, $ägare, $container] = dokumentflikKontext();

    $motor = dokumentflikItem($container, 'Motorn');

    dokumentflikBilaga($motor, $ägare, 'bild.jpg', 'image');
    dokumentflikBilaga($motor, $ägare, 'manual.pdf', 'document');

    $utan = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl($container))->assertOk());
    $med = dokumentflikProps(actingAs($ägare)->get(dokumentflikUrl($container, 'kind[]=image'))->assertOk());

    // Träfflistan smalnar av till bilden …
    expect(dokumentflikFilnamn($med))->toBe(['bild.jpg']);

    // … men antalen är desamma som utan filter.
    expect($med['filterOptions']['kinds'])->toBe($utan['filterOptions']['kinds'])
        ->and($med['filterOptions']['total'])->toBe($utan['filterOptions']['total'])
        ->and($med['filterOptions']['kinds'])->toBe(['image' => 1, 'document' => 1, 'other' => 0])
        ->and($med['filterOptions']['total'])->toBe(2);
});

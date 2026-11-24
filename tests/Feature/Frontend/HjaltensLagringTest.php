<?php

use App\Models\Account;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\actingAs;

/*
 * Issue 476 · Lagringskortet i hjälten, se
 * resources/js/components/HeroStorage.vue, resources/js/components/AppHero.vue,
 * resources/js/pages/Dashboard.vue, `storageQuota()` i
 * App\Http\Middleware\HandleInertiaRequests och [[ADR-0090 Dashboardens
 * hjälte]] § Tillägg 2026-11-24 · [[ADR-0081 All lagrad data räknas mot
 * kvoten]].
 *
 * Filen bevisar *Klart när* för `HeroStorage`:
 *
 * 1. Kortet ritas i hjälten på dashboarden och på alla sidor med `AppHero`,
 *    sist i sifferraden, och under `md:` bara på dashboarden.
 * 2. Talen är `storageQuota`:s: `usedBytes` är `fileBytes + textBytes`, och
 *    `barPercent` för 17 MB av 10 GB är cirka 0,17 — inte 0.
 * 3. Stapeln ritas med `barPercent` och har inget golv.
 * 4. Ett obegränsat tak ger `quota.used_unlimited` och ingen stapel.
 * 5. Hela kortet är en länk till `/settings/storage`, med ett tillgängligt
 *    namn och en synlig fokusring.
 * 6. `<h1>` står på samma höjd och hjälten ändrar inte höjd när `heroFigures`
 *    kommer: kortet läser en propp som redan finns vid första ritningen.
 * 7. `/settings/storage` ritar som förut, och kvotmodulen finns bara i
 *    mobilmenyn sedan issue 477.
 * 8. Containerformen (issue 477) säger containerns andel av kontots tak och
 *    ritar den som ett eget segment med bredden `container.percent`, utan
 *    minsta bredd.
 *
 * Källkodsproven läser formen, som GenomgangTest: komponenten ritas i
 * webbläsaren och går inte att rendera här. Kommentarerna rensas bort först,
 * så en mening i ett docblock inte kan nöja ett prov.
 *
 * Hjälparna har prefixet `hjallagring` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * Källkoden med kommentarer borta — samma tre slag som GenomgangTest rensar.
 */
function hjallagringUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * @return array{0: Account, 1: User}
 */
function hjallagringKonto(string $roll = 'owner', int $storage = 0, int $text = 0): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => $roll]);
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => $storage,
        'metadata_bytes' => $text,
    ]);

    return [$konto, $anvandare];
}

/**
 * Sätter kontots tak på gratisplanen, som `KvotmodulTest` gör: `null` är
 * obegränsat.
 */
function hjallagringTak(Account $konto, ?int $bytes): void
{
    $konto->subscription()->delete();

    $plan = $konto->currentPlan();
    $limits = $plan->limits;
    $limits['storage_bytes'] = $bytes;
    $plan->forceFill(['limits' => $limits])->save();
}

/**
 * @return array<string, mixed>
 */
function hjallagringProps(User $anvandare, string $url = '/dashboard'): array
{
    /** @var array{props: array<string, mixed>} $sida */
    $sida = actingAs($anvandare)->get($url)->assertOk()->viewData('page');

    return $sida['props'];
}

function hjallagringKort(): string
{
    return hjallagringUtanKommentarer(File::get(resource_path('js/components/HeroStorage.vue')));
}

// --- talen -----------------------------------------------------------------

/*
 * Klart när: `usedBytes` är `fileBytes + textBytes`, och de två kolumnerna
 * följer med var för sig.
 *
 * Talen är `usage_counter`s via ReadPlanUsage — ingen ny summering och ingen
 * SUM i vyn ([[ADR-0081 All lagrad data räknas mot kvoten]] § 1).
 */
it('bär filer och text var för sig och summerar dem till usedBytes', function () {
    [$konto, $anvandare] = hjallagringKonto('owner', 1000, 234);
    hjallagringTak($konto, 100000);

    $kvot = hjallagringProps($anvandare)['storageQuota'];

    expect($kvot['fileBytes'])->toBe(1000)
        ->and($kvot['textBytes'])->toBe(234)
        ->and($kvot['usedBytes'])->toBe(1234);
});

/*
 * Klart när: `barPercent` för 17 MB av 10 GB är cirka 0,17, inte 0.
 *
 * Andelen räknas i servern som containerns segment: två decimaler, så att en
 * liten andel inte avrundas bort. Stapeln har därför ingen minsta bredd att
 * falla tillbaka på.
 */
it('räknar barPercent med två decimaler så att 17 MB av 10 GB blir 0,17', function () {
    [$konto, $anvandare] = hjallagringKonto('owner', 17 * 1024 * 1024);
    hjallagringTak($konto, 10 * 1024 * 1024 * 1024);

    $kvot = hjallagringProps($anvandare)['storageQuota'];

    expect($kvot['usedBytes'])->toBe(17 * 1024 * 1024)
        ->and($kvot['barPercent'])->toBe(0.17)
        ->and($kvot['percent'])->toBe(0);
});

/*
 * Klart när: ett obegränsat tak ger `barPercent: null` — och därmed ingen
 * stapel — medan bytena står kvar.
 */
it('ger barPercent null för ett obegränsat tak', function () {
    [$konto, $anvandare] = hjallagringKonto('owner', 500, 20);
    hjallagringTak($konto, null);

    $kvot = hjallagringProps($anvandare)['storageQuota'];

    expect($kvot['limitBytes'])->toBeNull()
        ->and($kvot['barPercent'])->toBeNull()
        ->and($kvot['fileBytes'])->toBe(500)
        ->and($kvot['textBytes'])->toBe(20)
        ->and($kvot['usedBytes'])->toBe(520);
});

/*
 * Klart när: kortet ritar på alla sidor med `AppHero` — inställningssidorna
 * inräknade — utan en ny begäran.
 *
 * `storageQuota` är en delad propp, så talen finns redan i sidans första svar
 * på `/dashboard`, `/containers` och `/settings/storage`. Kortet hämtar
 * ingenting självt.
 */
it('bär talen på varje sida som ritar hjälten, utan en ny begäran', function () {
    [$konto, $anvandare] = hjallagringKonto('owner', 1000, 234);
    hjallagringTak($konto, 100000);

    foreach (['/dashboard', '/containers', '/settings/storage'] as $url) {
        $kvot = hjallagringProps($anvandare, $url)['storageQuota'];

        expect($kvot['fileBytes'])->toBe(1000)
            ->and($kvot['textBytes'])->toBe(234)
            ->and($kvot['usedBytes'])->toBe(1234)
            ->and($kvot['barPercent'])->toBe(round(1234 / 100000 * 100, 2));
    }
});

// --- formen ----------------------------------------------------------------

/*
 * Klart när: förbrukat av tak, stapeln och *Files* och *Text* var för sig.
 *
 * Huvudraden är `quota.used_of`, och med obegränsat tak `quota.used_unlimited`.
 * Underraden visar de två bytena med `formatByteSize()`, samma formatering som
 * kvotmeningen — ingen egen formatering och ingen summa.
 */
it('ritar förbrukat av tak och filer och text var för sig', function () {
    $kortet = hjallagringKort();

    expect($kortet)->toContain("t('quota.card.heading')")
        ->toContain("t('quota.used_of', { used: used.value, limit: limit.value })")
        ->toContain("t('quota.used_unlimited', { used: used.value })")
        ->toContain("t('quota.files')")
        ->toContain("t('quota.text')")
        ->toContain('formatByteSize(quota.fileBytes)')
        ->toContain('formatByteSize(quota.textBytes)');

    // Ingen summa och ingen egen räkning i klienten.
    expect($kortet)->not->toContain('reduce');

    // Och ingen begäran: talen kommer ur den delade proppen, inte ur en
    // hämtning.
    expect($kortet)->not->toContain('router');
    expect($kortet)->not->toContain('fetch(');
});

/*
 * Klart när: stapeln ritas med `barPercent` och inget golv.
 *
 * Bredden är den faktiska andelen, ordagrant ur den delade proppen. Ingen
 * `Math.max` eller `Math.min` — ett golv hade gjort 0,17 % till en synlig
 * stapel och påstått en förbrukning som inte finns.
 */
it('ritar stapeln med barPercent och inget golv', function () {
    $kortet = hjallagringKort();

    expect($kortet)->toContain(':style="{ width: `${quota.barPercent}%` }"')
        ->toContain('v-if="quota.barPercent !== null"')
        ->toContain('aria-hidden="true"');

    expect($kortet)->not->toContain('Math.max');
    expect($kortet)->not->toContain('Math.min');

    // Stapeln har ingen egen minsta bredd: klassen på segmentet är höjd och
    // rundning, ingenting som ger det en bredd vid sidan av andelen.
    expect($kortet)->toMatch('/class="block h-2 rounded-pill"[^>]*:style="\{ width: `\$\{quota\.barPercent\}%` \}"/s');
});

// --- containerformen (issue 477) -------------------------------------------

/*
 * Klart när: containerformen säger containerns andel av kontots tak, och
 * stapeln är kontots med containerns andel som ett eget segment.
 *
 * Huvudraden blir `quota.in_container` (eller `_unlimited` vid ett obegränsat
 * tak) med containerns byte ur `storageQuota.container.bytes`, medan *Files*
 * och *Text* fortfarande gäller kontot. Samma form som `StorageQuota`.
 */
it('säger containerns andel av kontots tak i containerformen', function () {
    $kortet = hjallagringKort();

    expect($kortet)->toContain("t('quota.in_container', { container, used: used.value, limit: limit.value })")
        ->toContain("t('quota.in_container_unlimited', { container, used: used.value })")
        ->toContain('formatByteSize(quota.value.container.bytes)');

    // Underraden gäller kontot i båda formerna: ingen containerbytes där.
    expect($kortet)->toContain('formatByteSize(quota.fileBytes)')
        ->toContain('formatByteSize(quota.textBytes)');
});

/*
 * Klart när: containerns segment har bredden `storageQuota.container.percent`
 * och ingen minsta bredd.
 *
 * Bredden är serverns `percent`, ordagrant — ingen `Math.max`, ingen egen
 * klass som ger segmentet en minsta bredd. Det ritas bara i containerformen,
 * ovanpå kontots stapel i en starkare ton.
 */
it('ritar containerns segment med container.percent och utan golv', function () {
    $kortet = hjallagringKort();

    expect($kortet)->toContain('const containerWidth = computed(() => quota.value?.container?.percent ?? 0)')
        ->toContain(':style="{ width: `${containerWidth}%` }"');

    // Spåret är relativt, och segmentet är absolut placerat ovanpå kontots.
    expect($kortet)->toMatch('#class="relative h-2 w-full overflow-hidden rounded-pill"#')
        ->and($kortet)->toMatch('#<span\s+v-if="inContainer"\s+class="absolute left-0 top-0 block h-2 rounded-pill"[^>]*:style="\{ width: `\$\{containerWidth\}%` \}"#s');

    expect($kortet)->not->toContain('Math.max')
        ->and($kortet)->not->toContain('Math.min');
});

/*
 * Klart när: hela kortet är länken till `/settings/storage` också i
 * containerformen.
 *
 * Det är samma rot-`<Link>` i båda formerna — formen byter bara texten och
 * segmentet — så en container når lagringssidan på samma sätt som ett konto.
 */
it('länkar till /settings/storage också i containerformen', function () {
    $kortet = hjallagringKort();

    expect($kortet)->toContain('<Link')
        ->toContain('href="/settings/storage"')
        ->toContain('v-if="quota"');
});

/*
 * Klart när: hela kortet är en länk till `/settings/storage`, med ett
 * tillgängligt namn och en synlig fokusring.
 *
 * Länken är kortets rot och inte en knapp i hörnet: rubriken, siffrorna och
 * stapeln ligger inuti den. Namnet kommer ur `quota.card.open`, och fokus
 * syns med designsystemets ring — en nollställd outline utan ring river
 * tangentbordsarbetet (issue 68a).
 */
it('gör hela kortet till en länk med namn och fokusring', function () {
    $kortet = hjallagringKort();

    expect($kortet)->toContain('<Link')
        ->toContain('href="/settings/storage"')
        ->toContain(":aria-label=\"t('quota.card.open')\"")
        ->toContain('focus-visible:ring-2')
        ->toContain('focus-visible:ring-focus');

    // Ingen naken ankartagg och ingen knapp: hela ytan är `Link`.
    expect($kortet)->not->toContain('<a ');
    expect($kortet)->not->toContain('<button');
});

/*
 * Klart när: kortet står sist i hjältens sifferrad och ritas först när den
 * delade proppen finns.
 *
 * `AppHero` ritar kortet efter brickorna och `HeroCosts`, och kortet läser
 * `storageQuota` — som redan finns vid första ritningen, till skillnad från
 * `heroFigures`. Radens golv (`md:min-h-[102px]`) står kvar, så hjälten byter
 * inte höjd när talen kommer.
 */
it('ritar kortet sist i hjälten och oberoende av heroFigures', function () {
    $hjalten = hjallagringUtanKommentarer(File::get(resource_path('js/components/AppHero.vue')));

    expect($hjalten)->toContain('<HeroStorage')
        ->toContain('v-if="storageQuota"')
        ->toContain('tone="on-image"')
        ->toContain('md:min-h-[102px]');

    expect(strpos($hjalten, '<HeroStorage'))
        ->toBeGreaterThan(strpos($hjalten, '<HeroCosts'));

    expect(strpos($hjalten, '<HeroStorage'))
        ->toBeGreaterThan(strpos($hjalten, '<DashboardStats'));

    // Kortet är inte villkorat av `loaded`: det ritas i båda lägena.
    expect($hjalten)->not->toContain('v-if="loaded && storageQuota"');
});

/*
 * Klart när: under `md:` står kortet under bandet på dashboarden, efter
 * brickorna och `HeroCosts`, och på ingen annan sida.
 *
 * `AppHero`s sifferrad är `hidden … md:flex`, så den korta formen ritas bara
 * i `Dashboard.vue`s `md:hidden`-block. Ingen annan sida ritar kortet själv
 * — de får det genom `AppHero`.
 */
it('ritar kortet under bandet bara på dashboarden', function () {
    $sida = hjallagringUtanKommentarer(File::get(resource_path('js/pages/Dashboard.vue')));

    expect($sida)->toContain('md:hidden')
        ->toContain('<HeroStorage');

    expect(strpos($sida, '<HeroStorage'))
        ->toBeGreaterThan(strpos($sida, '<HeroCosts'));

    expect(strpos($sida, '<HeroStorage'))
        ->toBeGreaterThan(strpos($sida, '<DashboardStats'));

    // Ingen annan fil ritar kortet själv: bara hjälten, dashboarden,
    // containerns hjälte och komponenten själv nämner den.
    foreach (File::allFiles(resource_path('js')) as $fil) {
        if (in_array($fil->getFilename(), ['AppHero.vue', 'ContainerHero.vue', 'Dashboard.vue', 'HeroStorage.vue'], true)) {
            continue;
        }

        expect(File::get($fil->getPathname()))->not->toContain('<HeroStorage');
    }
});

/*
 * Klart när (issue 476, uppdaterat i 477): `/settings/storage` ritar som
 * förut, och kvotmodulen står kvar i mobilmenyn medan hjälten bär kortet.
 *
 * Issue 477 tog bort modulen ur skalets topprad; menyns instans med panelen är
 * kvar, och kortet länkar till sidan utan att ersätta den.
 */
it('lämnar lagringssidan och kvotmodulen i mobilmenyn orörda', function () {
    $layout = hjallagringUtanKommentarer(File::get(resource_path('js/layouts/AppLayout.vue')));
    $meny = hjallagringUtanKommentarer(File::get(resource_path('js/components/MobileMenu.vue')));
    $sidan = hjallagringUtanKommentarer(File::get(resource_path('js/pages/Settings/Storage.vue')));

    expect($layout)->not->toContain('<StorageQuota')
        ->and($meny)->toContain('<StorageQuota')
        ->and($sidan)->not->toContain('<HeroStorage');

    // Kortet länkar till sidan; det är den enda kopplingen mellan dem.
    expect(hjallagringKort())->toContain('href="/settings/storage"');
});

/*
 * Klart när: varje nyckel kortet slår upp finns i katalogen.
 *
 * Samma form som `KvotmodulTest`: nycklarna läses ur källkoden i stället för
 * att räknas upp här, så en mening som byter namn följer med.
 */
it('har varje quota-nyckel i katalogen', function () {
    $katalog = require lang_path('en/ui.php');

    preg_match_all("/t\('(quota\.[a-z_]+)'/", hjallagringKort(), $traffar);

    expect($traffar[1])->not->toBeEmpty();

    foreach (array_unique($traffar[1]) as $nyckel) {
        expect(data_get($katalog, $nyckel))->toBeString("{$nyckel} saknas i lang/en/ui.php");
    }
});

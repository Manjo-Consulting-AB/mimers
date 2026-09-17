<?php

// rott-pa-basen: issue 77b — ordbyte i prosa (kommentar och testnamn), ingen kodändring; bas och head delar applikationskod.

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * Issue 60b · Bilagesektionens kö — flera filer, en i taget, med framdrift
 * per fil. Se resources/js/components/ItemAttachmentSection.vue,
 * App\Http\Controllers\AttachmentController,
 * App\Http\Controllers\ItemController::show() och `lang/en/ui.php`.
 *
 * 60a byggde sektionen, rutterna och kvotfelet för EN fil. Den här filen
 * prövar det som blev flera, och de fyra gränserna issuen vilar på:
 *
 * 1. **Ett anrop per fil** (Beslut 1). Kontraktet från issue 16a § Beslut 2
 *    står kvar: fyra filer är fyra POST, aldrig ett anrop med en array. Kön i
 *    vyn är sekventiell av det skälet — Inertia avbryter ett pågående besök —
 *    och här prövas följden av anropen: fyra rader, summan i förbrukningen.
 * 2. **Ett fel på en fil stoppar inte kön** (Beslut 4). Fil 2 av 4 spränger
 *    kvoten; fil 1, 3 och 4 går igenom, och bara den andra får ett fältfel.
 * 3. **Det tekniska taket prövas av båda** (Beslut 5). Vyn avvisar en för stor
 *    fil med serverns egen mening innan bytena skickas, och servern nekar
 *    samma fil när den postas direkt mot rutten. Vyns kontroll är en artighet,
 *    aldrig den enda.
 * 4. **Listan speglar serverns svar** (Beslut 6). En lyckad POST svarar en
 *    omdirigering med detaljvyns färska props, och en partiell omladdning av
 *    bilagelistan svarar med bilagelistan.
 *
 * Kön, dropzonen och framdriften kör i en webbläsare, och sviten har ingen.
 * Det de tre sista testerna gör i stället är samma sak som
 * tests/Feature/Frontend/BilagevyTest.php gör med `can.create`: de läser
 * resources/js/components/ItemAttachmentSection.vue och prövar de regler som
 * går att pröva i källkoden — att kön postar en fil per anrop, att
 * framdriften kommer ur `onProgress` och inte ur en gissning, att ingen rad
 * läggs in i listan lokalt, och att hela ytan ligger bakom `can.create`.
 *
 * Takgränsens form prövas på riktigt, mot den verkliga begränsaren: svaret är
 * en OMDIRIGERING med fältfel, inte en tom 429 (se testet). Att
 * `throttle:uploads` finns på rutten och att /api delar samma begränsare
 * prövas i tests/Feature/Attachment/UppladdningTest.php.
 *
 * Att ingen svensk sträng står kvar i en Vue-komponent och att varje ny nyckel
 * finns prövas av tests/Feature/Frontend/SprakTest.php, som
 * läser varenda fil under resources/js och jämför `lang/sv` mot `lang/en`.
 *
 * Hjälparna har prefixet `bilageko` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Storage::fake('files');
    withoutVite();
});

/**
 * Ett konto med en medlem, och en container med ett item under kontot. Båda på
 * svenska, så meningarna nedan kan jämföras mot `Lang::get(…, 'en')`.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function bilagekoKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

function bilagekoUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}";
}

/**
 * ETT anrop med EN fil — samma kropp som vyn skickar, en gång per fil
 * (issue 16a § Beslut 2). Innehållet börjar med filnamnet så att två filer
 * med olika namn får olika hash, och är exakt `$bytes` långt så att
 * förbrukningen går att räkna.
 */
function bilagekoLaddaUpp(
    User $anvandare,
    Container $container,
    Item $item,
    Account $konto,
    string $namn,
    int $bytes,
): TestResponse {
    return from(bilagekoUrl($container, $item))->actingAs($anvandare)->post(
        bilagekoUrl($container, $item).'/attachments',
        [
            'file' => UploadedFile::fake()->createWithContent(
                $namn,
                substr(str_pad($namn, $bytes, 'a'), 0, $bytes),
            ),
            'account' => $konto->ulid,
        ],
    );
}

/**
 * Förbrukningen på ett konto, läst ur räknaren. `AdjustUsage` skapar raden vid
 * behov, så ett konto utan uppladdningar har ingen rad och svaret är 0.
 */
function bilagekoForbrukning(Account $konto): int
{
    return (int) UsageCounter::query()->where('account_id', $konto->id)->value('storage_bytes');
}

/**
 * Uppladdningsytan ur komponenten: allt mellan `<template v-if="can.create">`
 * och dess stängande tagg. Ytan har ingen nästlad `<template>`, så det första
 * `</template>` efter markören stänger den — och därmed går det att pröva att
 * dropzonen, filväljaren och kön ligger innanför flaggan (Beslut 3).
 */
function bilagekoUppladdningsyta(): string
{
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    $start = mb_strpos($vy, '<template v-if="can.create">');
    $slut = mb_strpos($vy, '</template>', $start === false ? 0 : $start);

    expect($start)->not->toBeFalse('uppladdningsytan hittas inte i komponenten');
    expect($slut)->not->toBeFalse('uppladdningsytan stängs inte');

    return mb_substr($vy, $start, $slut - $start);
}

// --- taket: en prop, och serverns kontroll kvar -------------------------

it('bär det tekniska taket som en prop ur config, inte som en egen siffra', function () {
    config(['files.max_upload_bytes' => 3 * 1024 * 1024]);

    [$konto, $anvandare, $container, $item] = bilagekoKontext();

    actingAs($anvandare)->get(bilagekoUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Containers/Items/Show')
            ->where('maxUploadBytes', 3 * 1024 * 1024)
    );
});

it('nekar samma fil som vyn stoppar när den postas direkt mot rutten', function () {
    // Taket är detsamma i vyn och på servern: 60b § Beslut 5 skickar
    // `config('files.max_upload_bytes')` till vyn, och
    // StoreAttachmentRequest prövar samma tal med `max:`. Vyns kontroll sparar
    // en resa över en mobil uppkoppling — den ersätter ingenting.
    config(['files.max_upload_bytes' => 2048]);

    [$konto, $anvandare, $container, $item] = bilagekoKontext();

    $svar = bilagekoLaddaUpp($anvandare, $container, $item, $konto, 'stor.pdf', 3000);

    // Formuläret, inte en felsida: fältfelet på `file` är samma sorts svar
    // vyn ger lokalt, och det är serverns mening som gäller.
    $svar->assertRedirect(bilagekoUrl($container, $item));
    $svar->assertSessionHasErrors('file');

    expect(Attachment::query()->count())->toBe(0);
    expect(StoredFile::query()->count())->toBe(0);
    expect(Storage::disk('files')->allFiles())->toBe([]);
});

it('avvisar i vyn en fil över taket med serverns egen mening', function () {
    // Beslut 5: filen skickas aldrig, och meningen är serverns — samma nyckel
    // AttachmentController lägger på fältet `file`, med gränsen och filens
    // storlek i läsbar form. Nyckeln står i `lang/`, aldrig i .vue-filen.
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    expect($vy)->toContain('props.maxUploadBytes');
    expect($vy)->toContain("t('error.quota.max_file_size_exceeded'");
    expect($vy)->toContain('limit_bytes');
    expect($vy)->toContain('file_bytes');

    // Jämförelsen ligger i kön, före varje anrop: en fil över taket får sin
    // rad och sitt fel utan att `router.post` någonsin anropas för den.
    expect($vy)->toContain('file.size > props.maxUploadBytes');
});

// --- fyra filer, en i taget --------------------------------------------

it('laddar upp fyra filer som fyra anrop och ger fyra rader och summan i förbrukningen', function () {
    [$konto, $anvandare, $container, $item] = bilagekoKontext();

    $storlekar = [1000, 1001, 1002, 1003];

    foreach ($storlekar as $nummer => $bytes) {
        bilagekoLaddaUpp($anvandare, $container, $item, $konto, "fil-{$nummer}.pdf", $bytes)
            ->assertRedirect(bilagekoUrl($container, $item));
    }

    // Fyra anrop, en fil var — kontraktet från issue 16a § Beslut 2 står kvar.
    expect(Attachment::query()->count())->toBe(4);
    expect(bilagekoForbrukning($konto))->toBe(array_sum($storlekar));

    actingAs($anvandare)->get(bilagekoUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('attachments', 4)
    );
});

it('låter en fil spränga kvoten medan de andra går igenom', function () {
    // Beslut 4, och skälet till att kön inte avbryts vid första felet: den
    // vanligaste orsaken är en ENSKILD fil — för stor, eller den som råkade
    // fylla kvoten — och de andra hade gått igenom.
    sättPlangräns('free', 'storage_bytes', 3000);

    [$konto, $anvandare, $container, $item] = bilagekoKontext();

    bilagekoLaddaUpp($anvandare, $container, $item, $konto, 'fil-1.pdf', 1000)->assertRedirect();
    $svar = bilagekoLaddaUpp($anvandare, $container, $item, $konto, 'fil-2.pdf', 2500);
    $svar->assertRedirect();
    $svar->assertSessionHasErrors('file');

    // Fil 2 av 4: 1000 + 2500 är över 3000, och felet hamnar på DEN filen.
    $mening = session('errors')->get('file')[0];

    expect($mening)->toBe(Lang::get('ui.error.quota.storage_exceeded', [
        'limit_bytes' => Number::fileSize(3000),
        'used_bytes' => Number::fileSize(1000),
        'file_bytes' => Number::fileSize(2500),
    ], 'en'));

    // Kön fortsätter: fil 3 och 4 laddas upp ändå, och räknaren rör sig bara
    // med de filer som kom fram.
    bilagekoLaddaUpp($anvandare, $container, $item, $konto, 'fil-3.pdf', 500)->assertRedirect();

    $sista = bilagekoLaddaUpp($anvandare, $container, $item, $konto, 'fil-4.pdf', 500);
    $sista->assertRedirect();
    $sista->assertSessionHasNoErrors();

    expect(Attachment::query()->count())->toBe(3);
    expect(bilagekoForbrukning($konto))->toBe(2000);

    // "3 av 4 filer laddades upp": listan är serverns, och den har tre rader.
    actingAs($anvandare)->get(bilagekoUrl($container, $item))->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('attachments', 3)
    );
});

// --- listan: serverns svar, aldrig en optimistisk rad -------------------

it('svarar med bilagelistan och inte med hela sidans props på en partiell omladdning', function () {
    // Beslut 6: kön ritar sina egna rader bredvid listan, och listan är alltid
    // serverns. Att bilagelistan går att ladda om för sig är vad som gör den
    // till en lista och inte till en gissning.
    [$konto, $anvandare, $container, $item] = bilagekoKontext();

    bilagekoLaddaUpp($anvandare, $container, $item, $konto, 'manual.pdf', 1024)->assertRedirect();

    // Versionsheadern är den samma middleware skulle svara med: ett anrop
    // med fel version är 409, och det är inte det här testet prövar.
    $svar = actingAs($anvandare)->get(bilagekoUrl($container, $item), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(Request::create('/')),
        'X-Inertia-Partial-Component' => 'Containers/Items/Show',
        'X-Inertia-Partial-Data' => 'attachments',
    ]);

    // Ett svar på en partiell omladdning är JSON och inte en renderad sida,
    // så det läses som det är i stället för genom AssertableInertia.
    $svar->assertOk();

    $sida = $svar->json();

    expect($sida['component'])->toBe('Containers/Items/Show');
    expect($sida['props']['attachments'])->toHaveCount(1);
    expect($sida['props']['attachments'][0]['filename'])->toBe('manual.pdf');

    // Bilagelistan och den delade propen — inte detaljvyns tunga props.
    expect($sida['props'])->not->toHaveKey('links');
    expect($sida['props'])->not->toHaveKey('counterparts');
    expect($sida['props'])->not->toHaveKey('item');
});

it('lägger aldrig en rad i listan som servern inte svarat med', function () {
    // Beslut 6: raderna kommer ur detaljvyns props och kön är ett eget
    // tillstånd bredvid. Ingen `unshift`, ingen lokal rad som väntar på att
    // servern ska komma ikapp — det är just den raden som hade blivit kvar
    // som ett halvt tillstånd när en uppladdning avbryts.
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    expect($vy)->toContain('props.attachments.map');
    expect($vy)->not->toContain('unshift');
    expect($vy)->not->toContain('rows.value.push');
    expect($vy)->not->toContain('attachments.value.push');
});

// --- kön: en fil i taget, framdriften ur onProgress ---------------------

it('postar en fil per anrop och tar framdriften ur onProgress', function () {
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    // Ett anrop per fil (Beslut 1), och ett anrop i taget: nästa fil startas
    // först i föregående anrops återkallelse.
    expect($vy)->toContain('router.post');
    expect($vy)->toContain('forceFormData: true');
    expect($vy)->toContain('onSuccess');
    expect($vy)->toContain('onError');

    // Framdriften är `e.percentage` och ingenting annat (Beslut 2). Ingen
    // timer som ritar en stapel som rör sig utan att veta något.
    expect($vy)->toContain('onProgress');
    expect($vy)->toContain('event.percentage');
    expect($vy)->not->toContain('setInterval');
    expect($vy)->not->toContain('setTimeout');

    // Fyra tillstånd, och de ritas ur `lang/` (Beslut 8) — statusen är ett
    // värde, orden är nycklar.
    expect($vy)->toContain('item.attachment.status.');
    expect($vy)->toContain('item.attachment.summary');
    expect($vy)->toContain('item.attachment.dismiss');
});

it('har dropzonen som ett tillägg och filväljaren som den väg som alltid fungerar', function () {
    $yta = bilagekoUppladdningsyta();

    // Beslut 3: `drop` och `dragover` på dropzonen, och en vanlig
    // `<input type="file" multiple>` bredvid — den väg som fungerar med
    // tangentbord, skärmläsare och på en telefon.
    expect($yta)->toContain('@drop.prevent');
    expect($yta)->toContain('@dragover.prevent');
    expect($yta)->toContain('type="file"');
    expect($yta)->toContain('multiple');

    // Filväljaren är den väg filerna tar in i kön: `@change` fångar valet, och
    // `ref` låter vyn tömma DOM-värdet efteråt så att samma fil går att välja
    // igen.
    expect($yta)->toContain('@change="onSelect"');
    expect($yta)->toContain('ref="fileInput"');

    // Hela ytan — dropzon, väljare och kö — ligger bakom `can.create`
    // (Beslut 3 och 60 § Beslut 3): en `read`-mottagare ser ingen av dem.
    // Att servern nekar ändå prövas i BilagevyTest.
    expect($yta)->toContain('item.attachment.dropzone');
    expect($yta)->toContain('item.attachment.upload_heading');
});

// --- takgränsen: ett väntat svar, inte ett haveri -----------------------

it('gör takgränsen till ett fältfel i stället för en tom 429', function () {
    // Beslut 7. `throttle:uploads` släpper igenom 60 anrop per minut och
    // användare, och en kö på hundra filer slår i den.
    [$konto, $anvandare, $container, $item] = bilagekoKontext();

    for ($i = 0; $i < 60; $i++) {
        bilagekoLaddaUpp($anvandare, $container, $item, $konto, "fil-{$i}.pdf", 10)->assertRedirect();
    }

    $svar = bilagekoLaddaUpp($anvandare, $container, $item, $konto, 'fil-61.pdf', 10);

    // Svaret är INTE en 429: ThrottleRequestsException blir en omdirigering
    // med ett fältfel av samma closure som gör inloggningens takgräns till
    // ett formulärfel (issue 53a § Beslut 6, bootstrap/app.php). Filen kom
    // aldrig fram, och ingen rad skapades.
    $svar->assertStatus(302);
    $svar->assertRedirect(bilagekoUrl($container, $item));
    $svar->assertSessionHasErrors('email');
    expect(Attachment::query()->count())->toBe(60);

    // Meningen servern lägger där är inloggningens — den säger "för många
    // inloggningsförsök" om en uppladdning. Kön byter därför ut den mot sin
    // egen mening ur `lang/`, och en rå 429 (om en sådan någonsin når vyn)
    // får samma mening i stället för Inertias modalfönster.
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    expect($vy)->toContain("t('item.attachment.throttled')");
    expect($vy)->toContain('onHttpException');
    expect($vy)->toContain('response.status === 429');
});

it('lämnar inget halvt tillstånd när uppladdningen avbryts', function () {
    // 60 § Klart när. Ett avbrott på länken är varken ett lyckat eller ett
    // nekat anrop: Inertia kallar `onNetworkError` och varken `onSuccess` eller
    // `onError`. Utan den grenen blir raden stående som "laddar upp" med sin
    // sista procent, och kön står still för alltid — ett halvt tillstånd i
    // vyn, vilket är precis vad kravet förbjuder.
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    expect($vy)->toContain('onNetworkError');
    expect($vy)->toContain("t('item.attachment.interrupted')");

    // Filen läggs tillbaka som väntande och kön stannar: anropet kan ha nått
    // fram ändå, så raden påstår ingenting — och serverns lista är den som vet.
    expect($vy)->toContain("entry.status = 'waiting'");
    expect($vy)->toContain('entry.percentage = 0');
});

it('annonserar ett filfel i kön utan att fokus flyttas in i raden', function () {
    // Kön har inget FormField, alltså inget `file-error` för focusFirstError
    // att flytta fokus till — anropet blir en tyst no-op. Raden bär därför
    // `role="alert"` och ett eget id, så att felet läses upp när det ritas i
    // stället för att bara synas för den som ser skärmen.
    $vy = File::get(resource_path('js/components/ItemAttachmentSection.vue'));

    expect($vy)->toContain('role="alert"');
    expect($vy)->toContain('attachment-error-');
});

it('har köns meningar', function () {
    $nycklar = [
        'item.attachment.dropzone',
        'item.attachment.status.waiting',
        'item.attachment.status.uploading',
        'item.attachment.status.done',
        'item.attachment.status.failed',
        'item.attachment.summary',
        'item.attachment.throttled',
        'item.attachment.interrupted',
        'item.attachment.dismiss',
    ];

    foreach ($nycklar as $nyckel) {
        $mening = Lang::get("ui.{$nyckel}", [], 'en');

        // En saknad nyckel ger nyckeln själv tillbaka — samma regel som
        // resources/js/i18n/translate.js, och ett fel som ska synas här.
        expect($mening)->not->toBe("ui.{$nyckel}", "{$nyckel} saknas");
        expect(trim($mening))->not->toBe('');
    }

});

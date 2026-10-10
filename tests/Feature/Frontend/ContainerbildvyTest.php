<?php

use App\Actions\Container\RemoveContainerCover;
use App\Actions\Container\SetContainerCover;
use App\Actions\Usage\AdjustUsage;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\ImageDerivative;
use App\Models\StoredFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\withoutVite;

/*
 * Issue 159 · Containerns bild i gränssnittet, se [[ADR-0047 Containerns bild]]
 * § Beslut och [[M23 Mobilen och kartan]] § 159.
 *
 * Filen prövar YTAN: att bilden syns på de tre ställena — containerlistan,
 * dashboardens kort och containerns topprad — att den neutrala ytan tar vid när
 * bilden tas bort, att pennan bara ritas för den som får ändra containern, att
 * båda vägarna öppnar samma ark, att serverns fel blir en översatt mening, och
 * att listan och dashboarden kostar ett konstant antal frågor.
 *
 * Datamodellen och grinden är issue 158 och prövas av
 * tests/Feature/Attachment/ContainerbildTest.php och
 * tests/Feature/Container/ContainerbildBehorighetTest.php. Här byggs bilden
 * med App\Actions\Container\SetContainerCover — samma action som rutten
 * anropar — så att filen inte blir ett andra prov av referensräkningen.
 *
 * Hjälparna har prefixet `bildvy` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    withoutVite();

    // Kön ritar miniatyrer i samma request annars (QUEUE_CONNECTION=sync i
    // phpunit.xml), och inga byten ska hamna i den riktiga storage/files/ —
    // samma mönster som ContainerbildTest.
    Queue::fake();
    Storage::fake('files');
});

/**
 * Ett ägarkonto med en medlem, och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function bildvyKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create(['name' => 'Båten'])];
}

/**
 * En giltig PNG med en känd nyans — samma grepp som ContainerbildTest, så att
 * två anrop i samma prov ger två olika innehållshashar och därmed två
 * `stored_file`-rader.
 */
function bildvyPng(int $nyans = 10): string
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, $nyans, 20, 30));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

function bildvyFoto(int $nyans = 10): UploadedFile
{
    return UploadedFile::fake()->createWithContent('bat.png', bildvyPng($nyans));
}

/**
 * Sätter bilden genom actionen, som $användare — samma väg rutten går.
 */
function bildvySätt(User $användare, Container $container, Account $konto, int $nyans = 10): Attachment
{
    actingAs($användare);

    return app(SetContainerCover::class)->handle($container, bildvyFoto($nyans), $användare, $konto);
}

/**
 * Antalet frågor $anrop ställer, mätt i en KALL request — samma mätning som
 * DashboardbrickorTest gör: det första anropet värmer guarderna, kontocachen
 * och `last_active_at`, och `ResolveItemScope` memoiserar per
 * `{user, container}` i en `scoped`-bindning som överlever mellan anropen.
 *
 * Tiden fryses runt mätningarna (issue 477): UpdateLastActiveAt skriver
 * `user.last_active_at` med sekundupplösning, och faller en sekundgräns mellan
 * anropen blir det en UPDATE extra.
 */
function bildvyFrågor(Closure $anrop): int
{
    $anrop();

    app()->forgetScopedInstances();

    $frågor = 0;

    DB::listen(function ($query) use (&$frågor) {
        if (! str_contains($query->sql, 'last_active_at')) {
            $frågor++;
        }
    });

    $anrop();

    return $frågor;
}

/**
 * `<ContainerCover ...>`-taggen ur en fil, så att nålarna hör till just den
 * komponenten och inte till en granne på samma sida. `<ContainerCoverSheet` är
 * en ANNAN komponent och fångas inte: `\b` faller på `S` efter `ContainerCover`.
 */
function bildvyTagg(string $fil, string $komponent = 'ContainerCover'): string
{
    preg_match('/<'.$komponent.'\b[^>]*>/', File::get(resource_path($fil)), $träff);

    return $träff[0] ?? '';
}

/*
 * Klart när: en uppladdad bild syns i containerlistan, på dashboardkortet och i
 * toppraden.
 *
 * Alla tre läser `cover` ur samma form — `{ ulid, variants }` — och den kommer
 * ur App\Http\Resources\ContainerResource::cover() respektive
 * App\Actions\Container\ListContainerSummaries för kortet. Provet jämför mot
 * bilagans egen ULID och inte mot ett påhittat värde: det är samma bild, och
 * samma ULID är det enda som bevisar det.
 */
it('visar bilden i containerlistan, på dashboardkortet och i toppraden', function () {
    [$konto, $anvandare, $container] = bildvyKontext();

    $bilaga = bildvySätt($anvandare, $container, $konto);

    // En miniatyr finns: derivatet skapas av kön i drift, och vyn ritar
    // `?variant=thumb` bara när servern säger att varianten finns (issue 61b
    // § Beslut 1). Utan raden hade den ritat originalet, vilket är rätt svar
    // för en nyuppladdad bild — därför prövas båda fallen här.
    ImageDerivative::factory()->create([
        'stored_file_id' => $bilaga->stored_file_id,
        'variant' => 'thumb',
        'storage_path' => $bilaga->storedFile->storage_path.'_thumb.jpg',
    ]);

    actingAs($anvandare)
        ->get('/containers')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Index')
            ->where('containers.0.cover.ulid', $bilaga->ulid)
            ->where('containers.0.cover.variants', ['thumb'])
        );

    actingAs($anvandare)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('containerGroups.0.containers.0.cover.ulid', $bilaga->ulid)
            ->where('containerGroups.0.containers.0.cover.variants', ['thumb'])
        );

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Overview')
            ->where('container.cover.ulid', $bilaga->ulid)
        );
});

/*
 * Klart när (issue 681): den begärda varianten ritas om den finns, annars
 * originalet.
 *
 * Regeln flyttade till `coverPresentation.js` i issue 683 § Beslut 1, när
 * väljaren i arket behövde samma adress. Provet låser den där den bor nu: den
 * begärda varianten om den finns, annars originalet, och vyn frågar modulen i
 * stället för att bära en egen formulering. Den gamla nålen —
 * `includes('thumb')` — är förbjuden, för den band komponenten till miniatyren
 * och lämnade hjälten och korten suddiga (fynd från testarna 2026-10-03).
 */
it('väljer den begärda varianten och annars originalet', function () {
    $modul = File::get(resource_path('js/components/coverPresentation.js'));
    $vy = File::get(resource_path('js/components/ContainerCover.vue'));

    expect($modul)->toContain('cover.variants?.includes(variant)')
        ->and($modul)->toContain('`/files/${cover.ulid}?variant=${available}`');

    // Vyn: förvalet `thumb` kvar, och regeln hämtas ur modulen.
    expect($vy)->toContain("variant: { type: String, default: 'thumb' }")
        ->and($vy)->toContain('coverUrl(props.cover, props.variant)')
        ->and($vy)->not->toContain('variants?.includes');

    // Den gamla regeln, ordagrant: borta.
    expect($modul.$vy)->not->toContain("includes('thumb')");
});

/*
 * Klart när (issue 681): hjälten och korten ber om `medium`, de små ytorna om
 * ingenting.
 *
 * Ett källkodsprov, för det som avgör är VILKEN variant anroparen ber om och
 * ingenting annat. Taggen skärs ut ur varje fil, så att nålen hör till just
 * `<ContainerCover>` och inte till en granne — `ContainerLayout.vue` bär också
 * `<ContainerCoverSheet` i toppraden.
 */
it('ritar medium i hjälten och på korten men thumb i toppraden', function () {
    foreach ([
        'js/components/ContainerHero.vue',
        'js/components/ContainerCard.vue',
        'js/pages/Containers/Index.vue',
    ] as $fil) {
        expect(bildvyTagg($fil))->toContain('variant="medium"');
    }

    // De små fyrkanterna: förvalet `thumb`, alltså ingen propp alls.
    // Toppradens fyrkant ritas av `ContainerLayout` och teleporteras in i
    // skalet (issue 275 · [[ADR-0056 Flytande navigering]] § 1).
    foreach ([
        'js/layouts/ContainerLayout.vue',
        'js/pages/Containers/Edit.vue',
    ] as $fil) {
        expect(bildvyTagg($fil))->not->toContain('variant');
    }
});

/*
 * Klart när (issue 681): servern skickar `medium` i `variants` när derivatet
 * finns.
 *
 * Formen `{ ulid, variants }` kommer ur App\Http\Resources\ContainerResource::
 * cover(), som SORTERAR varianterna — `medium` före `thumb`. Leveransen av
 * själva varianten prövas i tests/Feature/Attachment och rörs inte här.
 */
it('skickar medium i variants när derivatet finns', function () {
    [$konto, $anvandare, $container] = bildvyKontext();

    $bilaga = bildvySätt($anvandare, $container, $konto);

    foreach (['thumb', 'medium'] as $variant) {
        ImageDerivative::factory()->create([
            'stored_file_id' => $bilaga->stored_file_id,
            'variant' => $variant,
            'storage_path' => $bilaga->storedFile->storage_path.'_'.$variant.'.jpg',
        ]);
    }

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Overview')
            ->where('container.cover.variants', ['medium', 'thumb'])
        );
});

/*
 * Klart när: att ta bort bilden visar ytan med artens ikon.
 *
 * Ytan är `ContainerCover`s egen gren: är `cover` null ritas ett neutralt fält
 * med containertecknet i stället för en `<img>`, och den ritas på alla tre
 * ställena — därav tre kontroller efter borttagningen, inte en. Att raden
 * FINNS kvar (och att kortet behåller sin höjd) är halva poängen: en tom ram
 * är precis vad ADR-0047 § Beslut stänger ute.
 */
it('visar den neutrala ytan när bilden tas bort', function () {
    [$konto, $anvandare, $container] = bildvyKontext();

    bildvySätt($anvandare, $container, $konto);

    actingAs($anvandare);

    delete("/containers/{$container->ulid}/cover")->assertRedirect();

    actingAs($anvandare)
        ->get('/containers')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('containers.0.cover', null));

    actingAs($anvandare)
        ->get('/dashboard')
        ->assertInertia(fn (AssertableInertia $page) => $page->where('containerGroups.0.containers.0.cover', null));

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('container.cover', null));

    // Ytan: `<img>` när det finns en adress, tecknet när det inte gör det.
    $vy = File::get(resource_path('js/components/ContainerCover.vue'));

    expect($vy)->toContain('<img v-if="url"')
        ->and($vy)->toContain('v-else')
        ->and($vy)->toContain('<svg');
});

/*
 * Klart när: pennan visas inte för den som bara läser.
 *
 * Flaggan `can.update` räknas i App\Http\Controllers\ContainerController::
 * show() med samma policy som rutten prövar, och skalet ritar pennan ur den.
 * Provet går hela vägen: en `read`-mottagare ser flaggan falsk OCH får 403 på
 * skrivningen — flaggan är presentation, grinden är policyn.
 */
it('visar inte pennan för den som bara läser, och nekar skrivningen', function () {
    [$konto, $agaren, $container] = bildvyKontext();

    $läsare = User::factory()->create();
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => 'user',
        'grantee_id' => $läsare->id,
        'level' => 'read',
        'kind' => 'member',
        'granted_by_user_id' => $agaren->id,
    ]);

    actingAs($läsare)
        ->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', false));

    // Och den som får ändra ser den — annars vore provet grönt av fel skäl.
    actingAs($agaren)
        ->get("/containers/{$container->ulid}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('can.update', true));

    actingAs($läsare)
        ->post("/containers/{$container->ulid}/cover", ['file' => bildvyFoto(), 'account' => $konto->ulid])
        ->assertForbidden();

    actingAs($läsare)
        ->delete("/containers/{$container->ulid}/cover")
        ->assertForbidden();

    expect($container->refresh()->cover_attachment_id)->toBeNull()
        ->and(Attachment::withTrashed()->count())->toBe(0);
});

/*
 * Klart när: pennan och inställningarna öppnar samma ark.
 *
 * Ett källkodsprov, för det som skiljer de två vägarna är var de sitter och
 * ingenting annat: båda renderar `ContainerCoverSheet`, och arket bär de tre
 * raderna ur `lang/`. Ett eget ark på den ena ytan hade varit två ställen att
 * glömma en rad på.
 */
it('öppnar samma ark från pennan och från inställningarna', function () {
    $topprad = File::get(resource_path('js/layouts/ContainerLayout.vue'));
    $inställningar = File::get(resource_path('js/pages/Containers/Edit.vue'));
    $ark = File::get(resource_path('js/components/ContainerCoverSheet.vue'));

    expect($topprad)->toContain('<ContainerCoverSheet')
        ->and($inställningar)->toContain('<ContainerCoverSheet');

    // De tre valen, ur samma nycklar ([[ADR-0047 Containerns bild]] § Beslut).
    expect($ark)->toContain("t('container.cover.camera')")
        ->and($ark)->toContain("t('container.cover.device')")
        ->and($ark)->toContain("t('container.cover.remove')");

    // *Ta ett foto* är en filväljare med `capture` och ingen egen kamera.
    expect($ark)->toContain('capture="environment"')
        ->and($ark)->toContain('type="file"');

    foreach ([
        'container.cover.heading',
        'container.cover.edit',
        'container.cover.camera',
        'container.cover.device',
        'container.cover.remove',
        'container.cover.description',
        'flash.container-cover-updated',
        'flash.container-cover-removed',
        'error.attachment.not_image',
    ] as $nyckel) {
        expect(Lang::has("ui.{$nyckel}"))->toBeTrue("ui.{$nyckel} saknas i lang/en/ui.php");
    }
});

/*
 * Klart när (issue 626): arket exponerar öppnaren i STANDARDSLOTEN, och båda
 * anroparna fyller den.
 *
 * `ContainerCoverSheet.vue` ritar `<slot name="trigger" :open="show" />`, men
 * både pennan (`ContainerLayout.vue`, i toppraden) och inställningarna
 * (Containers/Edit.vue)
 * fyller STANDARDSLOTTEN med `v-slot="{ open }"`. Vue ritar då ingenting: en
 * namngiven slot utan innehåll är tom, och knappen respektive pennan blev
 * aldrig ett element i DOM:en. Det befintliga provet ovan såg bara att
 * `<ContainerCoverSheet` FANNS — inte att anroparen fyllde en annan slot.
 *
 * Kontraktet låses därför från båda sidor: arket har en standardslot som bär
 * `:open="show"` och ingen namngiven slot, och var och en av anroparna binder
 * den fyllda slotens `open` till `@click`. Provet är ett källkodsprov på samma
 * tre filer som grannen ovan, av samma skäl: det som skiljer de två vägarna är
 * var de sitter, och ingenting annat.
 */
it('ritar öppnaren i arkets standardslot som båda anroparna fyller', function () {
    $topprad = File::get(resource_path('js/layouts/ContainerLayout.vue'));
    $inställningar = File::get(resource_path('js/pages/Containers/Edit.vue'));
    $ark = File::get(resource_path('js/components/ContainerCoverSheet.vue'));

    // Arket: öppnaren i standardslotten, `show` bunden till slotens `open`.
    expect($ark)->toContain('<slot :open="show" />');

    // Och ingen namngiven slot — en `name="trigger"` hade tystat båda ytorna.
    expect($ark)->not->toContain('name="trigger"');

    // Anroparna: båda fyller sheet-elementets slot med `v-slot="{ open }"` och
    // binder öppnaren till `@click="open"`. Blocket skärs ut så att nålarna
    // hör till just den komponenten och inte till en granne på samma sida.
    foreach ([$topprad, $inställningar] as $anropare) {
        $block = substr($anropare, (int) strpos($anropare, '<ContainerCoverSheet'));
        $block = substr($block, 0, (int) strpos($block, '</ContainerCoverSheet>'));

        expect($block)->toContain('v-slot="{ open }"')
            ->and($block)->toContain('@click="open"');
    }
});

/*
 * Klart när: ett fel från servern, som kvoten eller en fil som inte är en bild,
 * visas med sin felkod översatt.
 *
 * Två fel och samma väg: `attachment.not_image` från
 * StoreAttachment::handleForContainer() och `quota.max_file_size_exceeded` från
 * App\Support\Plan\Entitlements blir båda ett FÄLTFEL på `file` med serverns
 * mening — aldrig en rå JSON-kropp mitt i en Inertia-sida (issue 60 § Beslut
 * 5). Meningen jämförs mot `lang/`-nyckeln och inte mot en avskrift, så en
 * ändrad text i katalogen inte kan göra provet grönt av fel skäl.
 */
it('gör en fil som inte är en bild till ett fältfel med översatt mening', function () {
    [$konto, $anvandare, $container] = bildvyKontext();

    $svar = from("/containers/{$container->ulid}")
        ->actingAs($anvandare)
        ->post("/containers/{$container->ulid}/cover", [
            'file' => UploadedFile::fake()->createWithContent('manual.txt', 'inte en bild'),
            'account' => $konto->ulid,
        ]);

    $svar->assertRedirect("/containers/{$container->ulid}");
    $svar->assertSessionHasErrors('file');
    expect($svar->getContent())->not->toContain('"error"');

    expect(session('errors')->get('file')[0])->toBe(Lang::get('ui.error.attachment.not_image', [], 'en'));

    // En avvisad fil lämnar varken rad, byten eller pekare efter sig.
    expect(Attachment::withTrashed()->count())->toBe(0)
        ->and(StoredFile::query()->count())->toBe(0)
        ->and(Storage::disk('files')->allFiles())->toBe([])
        ->and($container->refresh()->cover_attachment_id)->toBeNull();
});

it('gör en för stor fil till ett fältfel med gräns och filstorlek', function () {
    sättPlangräns('free', 'max_file_bytes', 1024);

    [$konto, $anvandare, $container] = bildvyKontext();

    $svar = from("/containers/{$container->ulid}")
        ->actingAs($anvandare)
        ->post("/containers/{$container->ulid}/cover", [
            'file' => UploadedFile::fake()->createWithContent('stor.png', bildvyPng().str_repeat('a', 2048)),
            'account' => $konto->ulid,
        ]);

    $svar->assertSessionHasErrors('file');

    $mening = session('errors')->get('file')[0];

    expect($mening)->toBe(Lang::get('ui.error.quota.max_file_size_exceeded', [
        'limit_bytes' => Number::fileSize(1024),
        'file_bytes' => Number::fileSize(2048),
    ], 'en'));

    expect($mening)->toContain(Number::fileSize(1024));

    expect($container->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * Kvoten går på det UPPLADDANDE kontot (ADR-0047 § Beslut).
 *
 * Här är uppladdaren medlem i ägarkontot, och då är ägarkontot det uppladdande
 * — arket skickar samma förval som itemets bilageuppladdning. Provet stänger
 * den billiga avvisningen: en medlem får ett fältfel i stället för en 500 när
 * kvoten är slut. Att en främmande mottagare i stället belastar sitt EGET
 * konto prövas för sig.
 */
it('gör en sprängd totalkvot till ett fältfel', function () {
    sättPlangräns('free', 'storage_bytes', 3000);

    [$konto, $anvandare, $container] = bildvyKontext();

    // Räknaren står strax under taket: bilden är liten men stor nog att föra
    // förbrukningen över gränsen.
    (new AdjustUsage)->handle($konto->id, bytesDelta: 2990);

    $svar = from("/containers/{$container->ulid}")
        ->actingAs($anvandare)
        ->post("/containers/{$container->ulid}/cover", [
            'file' => bildvyFoto(),
            'account' => $konto->ulid,
        ]);

    $svar->assertSessionHasErrors('file');

    expect(session('errors')->get('file')[0])
        ->toContain(Number::fileSize(3000))
        ->and($container->refresh()->cover_attachment_id)->toBeNull();
});

/*
 * Klart när: kontot som betalar är det UPPLADDANDE kontot, inte ägarkontot.
 *
 * En container-bred `write`-mottagare som är främmande för ägarkontot sätter
 * bilden genom RUTTEN och belastar sitt EGET kontos räknare — aldrig
 * ägarkontots ([[ADR-0047 Containerns bild]] § Beslut, [[ADR-0017
 * Missbruksvektorer]]). Provet stänger den vektor där varje mottagares
 * uppladdning hamnar på ägarkontots kvot.
 *
 * Kontot kommer i fältet `account`; arket skickar samma förval som itemets
 * bilageuppladdning — ägarkontot när användaren är medlem i det, annars hennes
 * eget första konto. Mottagaren här är medlem i ett annat konto, så det är det
 * som skickas, och det är det som ska bära bytena.
 */
it('låter en främmande write-mottagare belasta sitt eget konto', function () {
    [$ägarkonto, $ägaren, $container] = bildvyKontext();

    [$mottagarkonto, $mottagare] = kontoMedMedlem();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => 'write',
        'kind' => 'member',
        'granted_by_user_id' => $ägaren->id,
    ]);

    from("/containers/{$container->ulid}")
        ->actingAs($mottagare)
        ->post("/containers/{$container->ulid}/cover", [
            'file' => bildvyFoto(),
            'account' => $mottagarkonto->ulid,
        ])
        ->assertSessionHasNoErrors();

    $bilaga = $container->refresh()->coverAttachment;

    expect($bilaga)->not->toBeNull()
        ->and($bilaga->billed_account_id)->toBe($mottagarkonto->id);

    // Räknaren följer bilagans konto: mottagarens rad bär bytena, och
    // ägarkontot har ingen rad alls — containern skapades utan AdjustUsage.
    expect((int) DB::table('usage_counter')->where('account_id', $mottagarkonto->id)->value('storage_bytes'))
        ->toBe($bilaga->storedFile->byte_size)
        ->and(DB::table('usage_counter')->where('account_id', $ägarkonto->id)->value('storage_bytes'))->toBeNull();
});

/*
 * Klart när: listan och dashboarden gör ett konstant antal frågor oavsett antal
 * containers.
 *
 * **Bilden är det som mäts, och det är två olika mätningar.**
 *
 * Dashboarden är konstant rakt av: en container med bild och tio med bild
 * kostar samma tal, och det är vad provet jämför först.
 *
 * Containerlistan är INTE konstant i dag, och det är känt sedan issue 54
 * § Beslut 9: `can.update` räknas med en policyfråga per rad, och kontrollern
 * säger själv att priset är accepterat så länge listan inte pagineras. Provet
 * mäter därför det som den här issuen äger — vad BILDEN kostar — och jämför
 * tillväxten när en bild sätts på en container med tillväxten när en bild
 * sätts på nio. Är talen lika är bilden konstant (bilagan, bytena och
 * derivaten: tre frågor för hela listan), och en N+1:a över
 * `coverAttachment.storedFile.derivatives` hade gett nio gånger så många.
 *
 * Varje container bär en EGEN bild — egna byten och därmed en egen
 * `stored_file`-rad — så en N+1:a hade synts.
 */
it('kostar ett konstant antal frågor oavsett antalet containers', function () {
    [$konto, $anvandare, $container] = bildvyKontext();

    actingAs($anvandare);

    // Tiden fryst runt mätningarna (issue 477): UpdateLastActiveAt skriver
    // `user.last_active_at` vid varje autentiserat anrop, och faller en
    // sekundgräns mellan två mätningar blir det en UPDATE extra.
    Carbon::setTestNow(now());

    $listaUtanEn = bildvyFrågor(function () {
        get('/containers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('containers', 1));
    });

    bildvySätt($anvandare, $container, $konto, 10);

    $listaMedEn = bildvyFrågor(function () {
        get('/containers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('containers', 1));
    });

    $kortMedEn = bildvyFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('stats.containers', 1));
    });

    // Nio containrar till.
    foreach (range(2, 10) as $i) {
        Container::factory()->for($konto, 'account')->create(['name' => "Container {$i}"]);
    }

    // Alla tio UTAN bild: den första nollställs, så baslinjen är ren.
    app(RemoveContainerCover::class)->handle($container);

    $listaUtanTio = bildvyFrågor(function () {
        get('/containers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('containers', 10));
    });

    // Och så en bild på var och en.
    $nyans = 100;

    foreach (Container::query()->orderBy('id')->get() as $pärm) {
        bildvySätt($anvandare, $pärm, $konto, $nyans++);
    }

    $listaMedTio = bildvyFrågor(function () {
        get('/containers')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('containers', 10));
    });

    $kortMedTio = bildvyFrågor(function () {
        get('/dashboard')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('stats.containers', 10));
    });

    expect($kortMedTio)->toBe($kortMedEn)
        ->and($listaMedTio - $listaUtanTio)->toBe($listaMedEn - $listaUtanEn);

    Carbon::setTestNow();
});

/*
 * Klart när (issue 170): toppraden under `md:` är oförändrad av hjälten.
 *
 * Hjälten ritas bara över `md:` (`hidden md:block`, [[ADR-0050
 * Desktopdesignen]] § 2), och under brytpunkten är skalet från issue 151
 * oförändrat: den mörka toppraden bär containerns bild, namn och
 * tillbakaknapp, och pennan öppnar samma ark som förut. Provet fäster alla
 * fyra leden, så att hjältens `hidden md:block` inte kan bli en rad som
 * gömmer toppraden i stället — eller tvärtom.
 *
 * Kommentarer rensas bort först, som i grannfilerna: docblocken är svenska
 * med flit, och en regel som letar efter en klass ska inte kunna nöjas av en
 * mening i en kommentar.
 */
it('lämnar toppraden under md: oförändrad när hjälten ritas', function () {
    $vy = File::get(resource_path('js/layouts/ContainerLayout.vue'));
    $vy = (string) preg_replace('#/\*.*?\*/#s', '', $vy);
    $vy = (string) preg_replace('#<!--.*?-->#s', '', $vy);

    // Hjälten är dold under `md:`, och namnraden den ersätter gäller bara
    // över brytpunkten — samma `hidden ... md:block` som förut.
    expect($vy)->toContain('class="hidden md:block"');

    // Toppraden: teleporten, tillbakaknappen, bilden, namnet och pennan.
    // Sedan issue 275 ritas markupen av `ContainerLayout` och teleporteras in
    // i skalets mål `#shell-topbar` — skalet ligger utanför containerns layout
    // i kedjan, och en slot kan bara fyllas nedåt.
    $topprad = substr($vy, (int) strpos($vy, '<Teleport defer to="#shell-topbar">'));
    $topprad = substr($topprad, 0, (int) strpos($topprad, '</Teleport>'));

    expect($topprad)->toContain('href="/containers"')
        ->and($topprad)->toContain("t('nav.back')")
        ->and($topprad)->toContain('<ContainerCover :cover="container.cover" />')
        ->and($topprad)->toContain('{{ container.name }}')
        ->and($topprad)->toContain('<ContainerCoverSheet')
        ->and($topprad)->toContain('v-if="canUpdateCover"');

    // Och hjälten ritas av en sida som ber om den, i toppradens ställe över
    // brytpunkten — aldrig i den.
    expect($topprad)->not->toContain('<ContainerHero');
});

/*
 * Rutterna ligger bakom `auth`, som varje annan containerrutt: en utloggad
 * besökare möts av inloggningen och når aldrig en kontrollermetod.
 */
it('skickar en utloggad besökare till inloggningen', function () {
    [, , $container] = bildvyKontext();

    post("/containers/{$container->ulid}/cover", [])->assertRedirect('/login');
    delete("/containers/{$container->ulid}/cover")->assertRedirect('/login');
});

/*
 * Att byta bild genom ytan: den nya pekas ut och den gamla rensas. Det är
 * actionens regel sedan 158 — provet står här för att bevisa att RUTTEN går
 * hela vägen dit och att svaret är en omdirigering tillbaka till sidan arket
 * öppnades från, inte en JSON-kropp.
 */
it('sätter och byter bilden genom webbens rutt', function () {
    [$konto, $anvandare, $container] = bildvyKontext();

    $första = from("/containers/{$container->ulid}")
        ->actingAs($anvandare)
        ->post("/containers/{$container->ulid}/cover", ['file' => bildvyFoto(10), 'account' => $konto->ulid]);

    $första->assertRedirect("/containers/{$container->ulid}");
    $första->assertSessionHasNoErrors();

    $pekarPå = $container->refresh()->cover_attachment_id;
    expect($pekarPå)->not->toBeNull();

    $andra = from("/containers/{$container->ulid}")
        ->actingAs($anvandare)
        ->post("/containers/{$container->ulid}/cover", ['file' => bildvyFoto(200), 'account' => $konto->ulid]);

    $andra->assertSessionHasNoErrors();
    expect($container->refresh()->cover_attachment_id)->not->toBe($pekarPå);

    // Den gamla bilagan är rensad, och bara den nya finns kvar.
    expect(Attachment::withTrashed()->whereKey($pekarPå)->exists())->toBeFalse()
        ->and(Attachment::withTrashed()->count())->toBe(1);
});

/*
 * Klart när (issue 683): bilden ritas med fokuspunkten som `object-position`.
 *
 * Ett källkodsprov, för det som avgör är att PUNKTEN blir bildens stil och inget
 * annat. Adressen och variantregeln flyttade till `coverPresentation.js`
 * (Beslut 1), och komponenten importerar `coverUrl` därifrån — två
 * formuleringar av samma regel hade glidit isär. `:style` sitter på
 * `<img>`-taggen, alltså den yta som faktiskt beskär bilden.
 */
it('ritar bilden med fokuspunkten som object-position', function () {
    $vy = File::get(resource_path('js/components/ContainerCover.vue'));

    expect($vy)->toContain("from './coverPresentation.js'")
        ->and($vy)->toContain('coverUrl')
        ->and($vy)->toContain('objectPosition')
        ->and($vy)->toContain('cover?.focus');

    // `<img>`-taggen bär stilen — den här filens enda bild.
    expect(bildvyTagg('js/components/ContainerCover.vue', 'img'))->toContain(':style');
});

/*
 * Klart när (issue 683): arket låter användaren välja fokuspunkten med ett
 * klick.
 *
 * Klicket räknas om till procent ur bildens ruta (`getBoundingClientRect`), och
 * en knapp som aktiveras med tangentbordet har inga koordinater — `event.detail
 * === 0` fångar den och lämnar punkten orörd (Beslut 4). Rutten jämförs mot
 * ruttabellen: `containers.cover.focus` är samma adress controllern svarar på.
 */
it('låter arket välja fokuspunkten med ett klick', function () {
    $ark = File::get(resource_path('js/components/ContainerCoverSheet.vue'));

    expect($ark)->toContain('v-if="container.cover"')
        ->and($ark)->toContain('@click="chooseFocus"')
        ->and($ark)->toContain('event.detail === 0')
        ->and($ark)->toContain('getBoundingClientRect')
        ->and($ark)->toContain('router.patch')
        ->and($ark)->toContain('`/containers/${props.container.ulid}/cover/focus`');

    [, , $container] = bildvyKontext();

    expect(route('containers.cover.focus', $container, false))
        ->toBe("/containers/{$container->ulid}/cover/focus");
});

/*
 * Klart när (issue 683): en egen knapp centrerar bilden.
 *
 * `{ x: 50, y: 50 }` är mitten, samma punkt servern tolkar som `null`, och
 * knappen bär sin egen text ur katalogen (Beslut 3).
 */
it('centrerar bilden med en egen knapp', function () {
    $ark = File::get(resource_path('js/components/ContainerCoverSheet.vue'));

    expect($ark)->toContain("t('container.cover.focus_reset')")
        ->and($ark)->toContain('{ x: 50, y: 50 }');
});

/*
 * Klart när (issue 683): väljarens texter kommer ur översättningsfilen.
 *
 * Fyra nycklar under `container.cover` (Beslut 5), var och en både i katalogen
 * och använd i arket — en nyckel som bara finns i den ena är antingen död text
 * eller en rå sträng i vyn.
 */
it('hämtar väljarens texter ur översättningsfilen', function () {
    $ark = File::get(resource_path('js/components/ContainerCoverSheet.vue'));

    foreach ([
        'container.cover.focus_heading',
        'container.cover.focus_help',
        'container.cover.focus_label',
        'container.cover.focus_reset',
    ] as $nyckel) {
        expect(Lang::has("ui.{$nyckel}"))->toBeTrue("ui.{$nyckel} saknas i lang/en/ui.php");
        expect($ark)->toContain("t('{$nyckel}')");
    }
});

/*
 * Klart när (issue 683): fokuspunkten delas ända fram till vyn.
 *
 * Skrivningen går genom RUTTEN och läses tillbaka ur containerns vy och ur
 * dashboardens kort — samma `cover.focus` på båda, för formen kommer ur
 * App\Http\Resources\ContainerResource::cover() och kortet bär samma `cover`
 * som containerlistan (Beslut 3).
 */
it('delar fokuspunkten ända fram till vyn', function () {
    [$konto, $anvandare, $container] = bildvyKontext();

    bildvySätt($anvandare, $container, $konto);

    actingAs($anvandare)
        ->patch("/containers/{$container->ulid}/cover/focus", ['x' => 20, 'y' => 70])
        ->assertRedirect();

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Containers/Overview')
            ->where('container.cover.focus', ['x' => 20, 'y' => 70])
        );

    actingAs($anvandare)
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('containerGroups.0.containers.0.cover.focus', ['x' => 20, 'y' => 70])
        );
});

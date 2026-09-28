<?php

use App\Actions\Container\RemoveContainerCover;
use App\Actions\Container\SetContainerCover;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Issue 158 · Vem får sätta, byta, ta bort och HÄMTA en containerbild — se
 * [[ADR-0047 Containerns bild]] § Beslut, "Vem som får göra vad", och
 * [[ADR-0019 Filleverans]].
 *
 * Filen är syskon till tests/Feature/Attachment/ContainerbildTest.php, som
 * prövar formen och åtgärderna. Här prövas grinden, och den har två halvor
 * som är olika av flit:
 *
 * - **Byta och ta bort** kräver `ContainerPolicy::update`, samma pinne som
 *   att byta containerns namn. En `read`-mottagare och en ITEM-grant nekas
 *   båda — den senare för att `update()` kräver en container-bred rad
 *   (issue 70 § Beslut 9).
 * - **Se bilden** kräver `view` på containern, och dit når en
 *   omfångsbegränsad mottagare: bilden är containerns ansikte, och den som
 *   ser containerns namn i listan ser också dess bild (ADR-0047 § Beslut).
 *   Det är hela skillnaden mot en itembilaga, där [[ADR-0028 Åtkomst på
 *   itemnivå]] gäller och en itemgrant bara når sitt eget item.
 *
 * Ingen rutt och ingen yta byggs här — de är § 159. Rutten `files.download`
 * finns och binder `{attachment}` på bilagans ULID, utan scopeBindings, så
 * den bär en containerbilaga oförändrad.
 *
 * Hjälparna har prefixet `bildgrind`.
 */

beforeEach(function () {
    Queue::fake();
    Storage::fake('files');
    config(['files.internal_redirect' => false, 'files.url' => null]);
});

/**
 * Ett konto med en medlem, en container ägd av kontot, och en bild på
 * containern.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Attachment}
 */
function bildgrindKontext(): array
{
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $bilaga = bildgrindSätt($ägare, $container, $konto);

    return [$konto, $ägare, $container, $bilaga];
}

/**
 * Sätter bilden genom actionen, som $användare.
 */
function bildgrindSätt(User $användare, Container $container, Account $konto): Attachment
{
    actingAs($användare);

    return app(SetContainerCover::class)->handle(
        $container,
        UploadedFile::fake()->createWithContent('båt.png', bildgrindPng()),
        $användare,
        $konto,
    );
}

function bildgrindPng(): string
{
    $bild = imagecreatetruecolor(20, 20);
    imagefilledrectangle($bild, 0, 0, 19, 19, imagecolorallocate($bild, 10, 20, 30));

    ob_start();
    imagepng($bild);
    $byten = (string) ob_get_clean();
    imagedestroy($bild);

    return $byten;
}

/**
 * En grant som pekar på ett ENSKILT item — den omfångsbegränsade mottagaren.
 */
function bildgrindItemgrant(Container $container, User $mottagare, Item $item, string $nivå): void
{
    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $nivå,
        'kind' => 'member',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/*
 * Klart när: en containerbild kan sättas, bytas och tas bort av den som får
 * ändra containern och av ingen annan.
 *
 * Den tillåtande halvan: en medlem i ägarkontot och en container-bred
 * `write`-mottagare är samma pinne som ContainerPolicy::update ger. Båda
 * prövas, för den andra är den enda vägen in som inte är medlemskap.
 */
it('låter ägarkontots medlem sätta, byta och ta bort bilden', function () {
    [$konto, $ägare, $container] = bildgrindKontext();

    $första = $container->refresh()->cover_attachment_id;
    expect($första)->not->toBeNull();

    $andra = bildgrindSätt($ägare, $container, $konto);
    expect($container->refresh()->cover_attachment_id)->toBe($andra->id);

    actingAs($ägare);
    expect(app(RemoveContainerCover::class)->handle($container))->toBeTrue();
    expect($container->refresh()->cover_attachment_id)->toBeNull();
});

it('låter en container-bred write-mottagare sätta bilden', function () {
    [$konto, , $container] = bildgrindKontext();

    $mottagare = User::factory()->create();
    beviljaAccess($container, $mottagare, 'write', 'member');

    $bilaga = bildgrindSätt($mottagare, $container, $konto);

    expect($container->refresh()->cover_attachment_id)->toBe($bilaga->id);
});

/*
 * Den nekande halvan. Fyra mottagare som alla når containern på något vis men
 * inte får ÄNDRA den: en `read`-mottagare, en `create`-mottagare (under
 * `write` i laddern), en item-grant på `delete` (högst av alla nivåer, men
 * inte container-bred) och en främling.
 */
it('nekar den som inte får ändra containern', function (string $slag) {
    [$konto, $ägare, $container] = bildgrindKontext();

    $mottagare = User::factory()->create();
    $item = Item::factory()->for($container, 'container')->create();

    match ($slag) {
        'read' => beviljaAccess($container, $mottagare, 'read', 'member'),
        'create' => beviljaAccess($container, $mottagare, 'create', 'member'),
        'itemgrant' => bildgrindItemgrant($container, $mottagare, $item, 'delete'),
        default => null,
    };

    $förra = $container->refresh()->cover_attachment_id;

    actingAs($mottagare);

    expect(fn () => app(SetContainerCover::class)->handle(
        $container,
        UploadedFile::fake()->createWithContent('stulen.png', bildgrindPng()),
        $mottagare,
        $konto,
    ))->toThrow(AuthorizationException::class);

    expect(fn () => app(RemoveContainerCover::class)->handle($container))
        ->toThrow(AuthorizationException::class);

    // Ingenting hände: pekaren står kvar och ingen ny bilaga skrevs.
    expect($container->refresh()->cover_attachment_id)->toBe($förra);
    expect(Attachment::withTrashed()->count())->toBe(1);
})->with(['read', 'create', 'itemgrant', 'främling']);

/*
 * Klart när: bilden levereras till den som når containern, även med åtkomst
 * till ett enda item.
 *
 * Den omfångsbegränsade mottagaren har en grant på ETT item och ingenting
 * mer. Hon når containern genom ContainerPolicy::view — den har inget
 * `item_id IS NULL`-filter, med flit (issue 69 § Beslut 4) — och därmed
 * bilden. Det är skillnaden mot en itembilaga, som hade gett 403 här
 * ([[ADR-0028 Åtkomst på itemnivå]]).
 */
it('levererar bilden till en itemgrant-innehavare som når containern', function () {
    [, , $container, $bilaga] = bildgrindKontext();

    $mottagare = User::factory()->create();
    $item = Item::factory()->for($container, 'container')->create();
    bildgrindItemgrant($container, $mottagare, $item, 'read');

    actingAs($mottagare);

    get("/files/{$bilaga->ulid}")->assertOk();
});

/*
 * Klart när: bilden nekas till övriga.
 *
 * **403 och inte 404, och det är en avvikelse från issuens ordalydelse** —
 * se `## Frågor och antaganden` i PR:en. Grinden ÄR `view` på containern, som
 * ADR-0047 föreskriver, och en nekad grind svarar 403; 404 är reserverat för
 * en rad som inte finns eller ligger i papperskorgen. [[Testplan filer]]
 * § Del 2 kallar skillnaden en medveten gräns ("403 och 404 skiljer sig åt —
 * en ULID går inte att gissa"), och FilatkomstTest AssertForbidden för samma
 * fall på en itembilaga. Att svara 404 här hade gett samma rutt två svar för
 * samma sak beroende på vilket slag av bilaga anropet råkade träffa.
 *
 * En anonym begäran prövas inte: rutten bär `auth:sanctum`, så den som inte är
 * inloggad når aldrig fram (Beslut 2 i issue 19a). Det är därför "övriga" här
 * betyder en inloggad användare utan åtkomst till containern.
 */
it('nekar bilden med 403 till den som inte når containern', function () {
    [, , $container, $bilaga] = bildgrindKontext();

    $främling = User::factory()->create();
    Account::factory()->create();

    // auth()->forgetGuards() — Sanctum minns den förra testets användare i
    // samma process, se somAnvandare() i tests/Support/Testhjalpare.php.
    auth()->forgetGuards();

    actingAs($främling);

    get("/files/{$bilaga->ulid}")->assertForbidden();
});

/*
 * En mjukraderad container levererar inte sin bild. Samma regel som för en
 * itembilaga i en mjukraderad container (FilatkomstTest A4), och samma
 * uppslag: containern kommer ur accessSubject() och bär sitt SoftDeletes-
 * scope.
 */
it('nekar bilden när containern ligger i papperskorgen', function () {
    [, $ägare, $container, $bilaga] = bildgrindKontext();

    $container->delete();

    actingAs($ägare);

    get("/files/{$bilaga->ulid}")->assertNotFound();
});

/*
 * Grinden ställs mot CONTAINERN och inte mot ett item. Provet är
 * mutationsvägen: hade leveransen frågat `view` på `$attachment->item` — som
 * före issue 158 — hade uppslaget varit null och svaret 404 i stället för
 * 200, och en itemgrant-innehavare hade aldrig nått bilden.
 */
it('frågar containern och inte ett item när bilagan hör till en container', function () {
    [, $ägare, $container, $bilaga] = bildgrindKontext();

    expect($bilaga->item_id)->toBeNull();
    expect($bilaga->container_id)->toBe($container->id);

    actingAs($ägare);

    get("/files/{$bilaga->ulid}")->assertOk();
});

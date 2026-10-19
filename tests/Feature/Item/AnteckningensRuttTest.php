<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M42 · issue 324 — anteckningens rutt, logg och sidpropp, se [[ADR-0066
 * Itemets anteckning]] § 2, 6, 7 och 10.
 *
 * Filen prövar den skrivande ytan: att bara `notes` rörs, att grinden är
 * `update` på itemet och att sparningen loggas per arbetspass. Ritningen har
 * en egen fil (AnteckningensRitningTest.php), eftersom den är en egen Action
 * med sina egna löften.
 *
 * Hjälparna har prefixet `anteckning` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs. kontoMedMedlem() är en global testhjälpare i
 * tests/Support/Testhjalpare.php.
 */

/**
 * Ett konto med en ägare och en container ägd av kontot.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function anteckningKontext(): array
{
    [$konto, $anvandare] = kontoMedMedlem();

    return [$konto, $anvandare, Container::factory()->for($konto, 'account')->create()];
}

/**
 * @param  array<string, mixed>  $attribut
 */
function anteckningItem(Container $container, ?User $skapare = null, array $attribut = []): Item
{
    return Item::factory()->for($container, 'container')->create(array_merge([
        'name' => 'Motorn',
        'created_by_user_id' => ($skapare ?? User::factory()->create())->id,
        'created_by_account_id' => $container->account_id,
    ], $attribut));
}

function anteckningUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/notes";
}

/**
 * En mottagare utanför ägarkontot med en containerbred grant på angiven nivå.
 */
function anteckningMottagare(Container $container, string $niva): User
{
    $mottagare = User::factory()->create();

    ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $mottagare->id,
        'level' => $niva,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    return $mottagare;
}

/**
 * Anteckningssparningarna för itemet, i skrivordning.
 *
 * @return Collection<int, AuditLog>
 */
function anteckningLoggar(Item $item): Collection
{
    return AuditLog::query()
        ->where('item_id', $item->id)
        ->where('action', AuditLog::ACTION_ITEM_UPDATED)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();
}

/*
 * Klart när: `PATCH …/notes` sparar bara `notes` och svarar med `notes` och
 * `notes_html`, och kategorin, taggarna och omslaget är orörda efter en
 * sparning.
 *
 * Alla fyra fälten sätts före sparningen. Kategorin, taggen och bilagan är
 * valda värden som en bred `fill()`- eller `save()`-väg lätt hade kunnat
 * skriva över; rutten får bara röra `notes`.
 */
it('sparar bara anteckningen och svarar med texten och dess HTML', function () {
    [, $anvandare, $container] = anteckningKontext();

    $kategori = Category::factory()->for($container, 'container')->create(['name' => 'Framdrivning']);
    $tagg = Tag::factory()->for($container, 'container')->create(['name' => 'Motor', 'color' => '#ff0000']);

    $item = anteckningItem($container, $anvandare, ['category_id' => $kategori->id]);
    $item->tags()->attach($tagg);

    $bild = Attachment::factory()->for($item, 'item')->create([
        'filename' => 'impeller.jpg',
        'kind' => 'image',
    ]);

    $item->cover_attachment_id = $bild->id;
    $item->save();

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => '# Rubrik'])
        ->assertOk()
        ->assertJson([
            'notes' => '# Rubrik',
            'notes_html' => "<h1>Rubrik</h1>\n",
        ]);

    $item->refresh();

    expect($item->notes)->toBe('# Rubrik');
    expect($item->name)->toBe('Motorn');
    expect($item->category_id)->toBe($kategori->id);
    expect($item->cover_attachment_id)->toBe($bild->id);
    expect($item->tags()->pluck('tag.id')->all())->toBe([$tagg->id]);
});

/*
 * Klart när: ett fel i valideringen svarar 422 med fälten.
 *
 * Regeln är `nullable|string`, samma som `UpdateItemRequest` har för fältet.
 * Webben behåller Laravels vanliga valideringsfel (ADR-0020 § Konsekvenser),
 * så svaret bär `errors.notes` och inte `/api`:s felhölje.
 */
it('svarar 422 med fältfelet när anteckningen inte är en sträng', function () {
    [, $anvandare, $container] = anteckningKontext();
    $item = anteckningItem($container, $anvandare);

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => ['rad']])
        ->assertStatus(422)
        ->assertJsonValidationErrors('notes');

    expect($item->refresh()->notes)->toBeNull();
});

/*
 * Klart när: utan `update` svarar rutten 403.
 *
 * En `read`-mottagare når itemet — hon ser det — men får inte skriva i det.
 * Att ingenting sparades är halva kravet: ett 403 efter en skriven rad vore
 * värre än inget 403 alls.
 */
it('svarar 403 utan update på itemet och sparar ingenting', function () {
    [, , $container] = anteckningKontext();
    $item = anteckningItem($container);

    $lasare = anteckningMottagare($container, 'read');

    actingAs($lasare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Ska inte sparas'])
        ->assertForbidden();

    expect($item->refresh()->notes)->toBeNull();
});

/*
 * Klart när: `notesHtml` finns i itemsidans props, och `item.notes` står kvar
 * som rå text.
 *
 * Vyn behöver båda formerna: den ritade HTML:en i läsläget och den råa
 * Markdown-texten när fältet öppnas. En tom anteckning ger `null`, inte en
 * tom sträng — det är skillnaden mellan "ingen anteckning" och "en anteckning
 * som ritar ingenting".
 */
it('bär notesHtml i sidproppen och lämnar item.notes som rå text', function () {
    withoutVite();

    [, $anvandare, $container] = anteckningKontext();

    $medText = anteckningItem($container, $anvandare, ['notes' => '**fet**']);
    $utanText = anteckningItem($container, $anvandare, ['name' => 'Utan anteckning', 'notes' => null]);

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$medText->ulid}")
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Containers/Items/Show')
                ->where('item.notes', '**fet**')
                ->where('notesHtml', "<p><strong>fet</strong></p>\n")
        );

    actingAs($anvandare)
        ->get("/containers/{$container->ulid}/items/{$utanText->ulid}")
        ->assertOk()
        ->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Containers/Items/Show')
                ->where('item.notes', null)
                ->where('notesHtml', null)
        );
});

/*
 * Klart när: två sparningar inom tio minuter av samma användare ger en
 * loggrad.
 *
 * Klockan står stilla på en fast tid och flyttas fem minuter mellan
 * sparningarna. Att båda texterna faktiskt sparades prövas också — annars
 * hade en rad kunnat saknas för att den andra sparningen aldrig hände.
 */
it('loggar en rad för två sparningar inom tio minuter av samma användare', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    [, $anvandare, $container] = anteckningKontext();
    $item = anteckningItem($container, $anvandare);

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Första'])
        ->assertOk();

    Carbon::setTestNow('2026-10-18 12:05:00');

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Andra'])
        ->assertOk();

    expect(anteckningLoggar($item))->toHaveCount(1);
    expect($item->refresh()->notes)->toBe('Andra');

    Carbon::setTestNow();
});

/*
 * Klart när: en sparning efter tio minuter ger en ny rad.
 *
 * Klockan står på exakt tio minuter efter den första raden — "yngre än tio
 * minuter" är strikt, så gränsen ska ge en ny rad och inte smyga in i
 * arbetspasset.
 */
it('skriver en ny rad när arbetspasset är tio minuter gammalt', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    [, $anvandare, $container] = anteckningKontext();
    $item = anteckningItem($container, $anvandare);

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Första'])
        ->assertOk();

    Carbon::setTestNow('2026-10-18 12:10:00');

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Andra'])
        ->assertOk();

    expect(anteckningLoggar($item))->toHaveCount(2);

    Carbon::setTestNow();
});

/*
 * Klart när: en sparning av en ANNAN användare ger en ny rad.
 *
 * Arbetspasset hör till personen, inte till itemet: två personer som skriver
 * i samma anteckning är två händelser, även om de sker inom tio minuter.
 */
it('skriver en ny rad när en annan användare sparar', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    [, $anvandare, $container] = anteckningKontext();
    $item = anteckningItem($container, $anvandare);
    $skrivare = anteckningMottagare($container, 'write');

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Första'])
        ->assertOk();

    Carbon::setTestNow('2026-10-18 12:01:00');

    somAnvandare($skrivare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Andra'])
        ->assertOk();

    expect(anteckningLoggar($item))->toHaveCount(2);

    Carbon::setTestNow();
});

/*
 * Klart när: en sparning utan ändring ger ingen rad, och texten finns aldrig
 * i `meta`.
 *
 * Klockan flyttas förbi arbetspasset mellan de två sparningarna, så en rad
 * hade skrivits om ändringen hade räknats — undantaget för tio minuter kan
 * alltså inte maskera resultatet. `meta` jämförs i sin helhet: exakt
 * `['changed' => ['notes']]` betyder att texten inte finns där, varken som
 * nytt eller gammalt värde.
 */
it('skriver ingen rad när texten inte ändras och bär aldrig texten i meta', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    [, $anvandare, $container] = anteckningKontext();
    $item = anteckningItem($container, $anvandare);

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Samma text'])
        ->assertOk();

    Carbon::setTestNow('2026-10-18 12:12:00');

    actingAs($anvandare)
        ->patchJson(anteckningUrl($container, $item), ['notes' => 'Samma text'])
        ->assertOk();

    $loggar = anteckningLoggar($item);

    expect($loggar)->toHaveCount(1);
    expect($loggar->first()->meta)->toBe(['changed' => ['notes']]);

    Carbon::setTestNow();
});

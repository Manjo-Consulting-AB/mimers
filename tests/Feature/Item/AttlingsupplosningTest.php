<?php

use App\Actions\Item\ResolveItemDescendants;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemLink;
use Illuminate\Support\Facades\DB;

/*
 * Issue 90 · Ättlingarna får en egen upplösning — itemet och allt som hänger
 * under det, längs `item_link`-kanter där `relation` är `parent`. Se
 * [[ADR-0040 Underträdets summor]] § Beslut och
 * App\Actions\Item\ResolveItemDescendants.
 *
 * Fixturen är ADR:ns: en båt med motor och mast som barn, en impeller under
 * motorn och ett relaterat par (motorn och drevet).
 *
 *   båt
 *   ├── motor ── impeller
 *   └── mast
 *   motor  related  drev
 *
 * Ingen användare och ingen grant förekommer någonstans i filen: den här
 * upplösningen svarar på vad som hänger under ett item, aldrig på vem som
 * får se det. Det är hela skillnaden mot App\Actions\Access\ResolveItemScope,
 * som har sina egna tester i tests/Feature/Omfang.
 *
 * Kanterna skrivs DIREKT i tabellen, förbi App\Actions\Item\LinkItems.
 * Actionen är garanten för att API:et aldrig skapar en cykel eller en
 * `child`-rad, och den garanten ska inte kunna maskera ett fel i vandringen.
 */

/**
 * Båten och dess delar, i EN container — i ordningen [$container, $båt,
 * $motor, $mast, $impeller, $drev].
 *
 * @return array{0: Container, 1: Item, 2: Item, 3: Item, 4: Item, 5: Item}
 */
function attlingBåt(): array
{
    $container = Container::factory()->create();

    $båt = attlingItem($container, 'Båten');
    $motor = attlingItem($container, 'Motorn');
    $mast = attlingItem($container, 'Masten');
    $impeller = attlingItem($container, 'Impellern');
    $drev = attlingItem($container, 'Drevet');

    attlingKant($båt, $motor);
    attlingKant($båt, $mast);
    attlingKant($motor, $impeller);

    // Motorn skapas före drevet och bär därför lägst id, så den kanoniska
    // formen — lägst id först, som LinkItems normaliserar — är just den här.
    // Det gör att en vandring som följde ALLA utgående kanter från motorn
    // hade dragit med sig drevet, vilket är vad testet ska fånga.
    attlingKant($motor, $drev, 'related');

    return [$container, $båt, $motor, $mast, $impeller, $drev];
}

function attlingItem(Container $container, string $namn): Item
{
    return Item::factory()->for($container, 'container')->create(['name' => $namn]);
}

/**
 * En kant skriven direkt i tabellen. `$från` är föräldern för en
 * `parent`-rad — den kanoniska riktningen, samma som LinkItems skriver.
 */
function attlingKant(Item $från, Item $till, string $relation = 'parent'): void
{
    ItemLink::query()->insert([
        'from_item_id' => $från->id,
        'to_item_id' => $till->id,
        'relation' => $relation,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Antalet frågor $anrop ställer. Actionen varken memoiserar eller cachar,
 * så varje räknad fråga är en actionen själv står för.
 */
function attlingFrågor(Closure $anrop): int
{
    $antal = 0;

    DB::listen(function () use (&$antal): void {
        $antal++;
    });

    $anrop();

    return $antal;
}

it('returnerar itemet och hela dess underträd, transitivt och utan djuptak', function () {
    [$container, $båt, $motor, $mast, $impeller] = attlingBåt();

    // Tolv nivåer under impellern: ett djuptak hade synts här.
    $kedja = [$impeller];
    $förälder = $impeller;

    for ($nivå = 1; $nivå <= 12; $nivå++) {
        $barn = attlingItem($container, "Nivå {$nivå}");
        attlingKant($förälder, $barn);

        $kedja[] = $barn;
        $förälder = $barn;
    }

    $underträd = app(ResolveItemDescendants::class)->handle($båt);

    $förväntat = [$båt->id, $motor->id, $mast->id, ...array_map(fn (Item $item) => $item->id, $kedja)];

    expect($underträd)->toEqualCanonicalizing($förväntat);

    // Sexton items: båten, motorn, masten, impellern och tolv nivåer under
    // den. Det egna id:t ligger först.
    expect($underträd)->toHaveCount(16);
    expect($underträd[0])->toBe($båt->id);
});

it('drar aldrig uppåt: ett barns underträd är dess eget', function () {
    [, $båt, $motor, , $impeller] = attlingBåt();

    $underträd = app(ResolveItemDescendants::class)->handle($motor);

    // Motorn får sin impeller — och varken båten över sig eller båtens
    // andra gren, masten, som ligger vid sidan.
    expect($underträd)->toEqualCanonicalizing([$motor->id, $impeller->id]);

    expect(app(ResolveItemDescendants::class)->handle($impeller))->toBe([$impeller->id]);
});

it('drar aldrig med sig något över en related-kant', function () {
    [$container, $båt, $motor, $mast, $impeller, $drev] = attlingBåt();

    // Ett barn under drevet, så att ett fel som följer related-kanten drar
    // in en hel gren och inte bara en nod.
    $propeller = attlingItem($container, 'Propellern');
    attlingKant($drev, $propeller);

    $underträd = app(ResolveItemDescendants::class)->handle($båt);

    expect($underträd)->not->toContain($drev->id);
    expect($underträd)->not->toContain($propeller->id);
    expect($underträd)->toEqualCanonicalizing([$båt->id, $motor->id, $mast->id, $impeller->id]);
});

it('tolkar en child-kant i rätt riktning och drar aldrig uppåt', function () {
    $container = Container::factory()->create();

    $förälder = attlingItem($container, 'Föräldern');
    $barn = attlingItem($container, 'Barnet');
    $barnbarn = attlingItem($container, 'Barnbarnet');

    // En `child`-rad kan bara ha kommit förbi LinkItems — relationen lagras
    // kanoniskt (`parent` skrivs, `child` härleds). Raden säger att `from`
    // är BARN till `to`, alltså är föräldern `to`, och vandringen ska läsa
    // den nedåt i stället för att ta `from` för en förälder.
    attlingKant($barn, $förälder, 'child');
    attlingKant($barn, $barnbarn);

    $resolver = app(ResolveItemDescendants::class);

    expect($resolver->handle($förälder))->toEqualCanonicalizing([$förälder->id, $barn->id, $barnbarn->id]);

    // Från barnet går vandringen nedåt, aldrig uppåt: föräldern är inte med.
    expect($resolver->handle($barn))->toEqualCanonicalizing([$barn->id, $barnbarn->id]);
});

it('räknar ett item som nås längs två vägar en gång', function () {
    [$container, $båt, $motor, $mast, $impeller] = attlingBåt();

    // Flera föräldrar är tillåtet — grafen är en DAG, inte ett träd.
    attlingKant($mast, $impeller);

    $underträd = app(ResolveItemDescendants::class)->handle($båt);

    expect($underträd)->toEqualCanonicalizing([$båt->id, $motor->id, $mast->id, $impeller->id]);
    expect(array_count_values($underträd)[$impeller->id])->toBe(1);

    // Den ena vägen räcker: mastens underträd är ändå detsamma.
    expect(app(ResolveItemDescendants::class)->handle($mast))->toEqualCanonicalizing([$mast->id, $impeller->id]);
});

it('avslutar vandringen på en cykel som skrivits förbi LinkItems', function () {
    $container = Container::factory()->create();

    // Cykeln skapas FÖRBI LinkItems — den hindrar den via API:et med
    // `item_link.cycle`, men en migrering, en import eller ett fel i
    // kontrollen själv kan lägga raden där ändå. En vandring som snurrar
    // för alltid är en hängd request och en död kö, se [[ADR-0040
    // Underträdets summor]] § Konsekvenser.
    $a = attlingItem($container, 'A');
    $b = attlingItem($container, 'B');
    $c = attlingItem($container, 'C');

    attlingKant($a, $b);
    attlingKant($b, $c);
    attlingKant($c, $a);

    $underträd = app(ResolveItemDescendants::class)->handle($a);

    // Testet hänger sviten om försvaret saknas — det finns ingen timeout att
    // falla tillbaka på. Att den här raden nås alls är beviset.
    expect($underträd)->toEqualCanonicalizing([$a->id, $b->id, $c->id]);
});

it('räknar inte ett mjukraderat item, och dess ättlingar faller bort med det', function () {
    [$container, $båt, $motor, $mast, $impeller] = attlingBåt();

    $ventil = attlingItem($container, 'Ventilen');
    attlingKant($impeller, $ventil);

    $motor->delete();

    $underträd = app(ResolveItemDescendants::class)->handle($båt);

    // Kedjan bryts vid motorn: impellern och ventilen under den faller bort
    // med den, precis som i åtkomstvandringen. Masten, som hänger direkt
    // under båten, är kvar.
    expect($underträd)->toEqualCanonicalizing([$båt->id, $mast->id]);
});

it('ger ett tomt svar för ett mjukraderat item som startpunkt', function () {
    [, $båt, $motor, , $impeller] = attlingBåt();

    $motor->delete();

    // Det raderade itemet räknas inte — och ingenting hänger längre under
    // det, eftersom dess kanter föll bort i frågan.
    expect(app(ResolveItemDescendants::class)->handle($motor))->toBe([]);
    expect(app(ResolveItemDescendants::class)->handle($impeller))->toBe([$impeller->id]);
    expect(app(ResolveItemDescendants::class)->handle($båt))->toHaveCount(2);
});

it('ställer ett konstant antal frågor oavsett trädets djup och bredd', function () {
    // Ett smalt träd: en kedja om tre.
    [, $smalRot] = attlingSmalt();

    // Ett brett och djupt träd: en rot, åtta barn med åtta barnbarn var,
    // och en kedja om tio under det första barnbarnet — 83 items.
    [, $bredRot] = attlingBrett();

    $smal = attlingFrågor(fn () => app(ResolveItemDescendants::class)->handle($smalRot));
    $bred = attlingFrågor(fn () => app(ResolveItemDescendants::class)->handle($bredRot));

    // En fråga i båda fallen: kanterna hämtas en gång per container, och
    // ingen fråga ställs per nivå eller per item.
    expect($smal)->toBe(1);
    expect($bred)->toBe(1);
});

it('ställer en fråga för hela listan, inte en per rad', function () {
    [$container, $båt, $motor, $mast, $impeller, $drev] = attlingBåt();

    $rader = Item::query()->where('container_id', $container->id)->pluck('id')->all();

    $underträd = [];
    $resolver = app(ResolveItemDescendants::class);

    $frågor = attlingFrågor(function () use ($resolver, $container, $rader, &$underträd): void {
        $underträd = $resolver->forItems($container->id, $rader);
    });

    // Issue 92 räknar statusen för varje rad i itemlistan. En vandring per
    // rad hade varit precis den N+1 hela åtkomstlösningen byggdes för att
    // undvika: kanterna hämtas en gång per lista och slutningen sker i
    // minnet. Sex rader, en fråga.
    expect($frågor)->toBe(1);
    expect($underträd)->toHaveCount(count($rader));
    expect($underträd[$båt->id])->toEqualCanonicalizing([$båt->id, $motor->id, $mast->id, $impeller->id]);
    expect($underträd[$motor->id])->toEqualCanonicalizing([$motor->id, $impeller->id]);
    expect($underträd[$impeller->id])->toBe([$impeller->id]);
    expect($underträd[$drev->id])->toBe([$drev->id]);
});

it('ger en nyckel för varje begärd startpunkt, även en utan ättlingar', function () {
    [$container, , $motor, , $impeller] = attlingBåt();

    $underträd = app(ResolveItemDescendants::class)->forItems($container->id, [$motor->id, $impeller->id]);

    expect($underträd)->toHaveKeys([$motor->id, $impeller->id]);
    expect($underträd[$motor->id])->toEqualCanonicalizing([$motor->id, $impeller->id]);
    expect($underträd[$impeller->id])->toBe([$impeller->id]);

    // Ingen startpunkt alls är ett tomt svar, inte en fråga.
    expect(attlingFrågor(fn () => app(ResolveItemDescendants::class)->forItems($container->id, [])))->toBe(0);
});

/**
 * En kedja om tre items i sin egen container, i ordningen [$container, $rot].
 *
 * @return array{0: Container, 1: Item}
 */
function attlingSmalt(): array
{
    $container = Container::factory()->create();

    $rot = attlingItem($container, 'Rot');
    $mitten = attlingItem($container, 'Mitten');
    $topp = attlingItem($container, 'Toppen');

    attlingKant($rot, $mitten);
    attlingKant($mitten, $topp);

    return [$container, $rot];
}

/**
 * Ett brett träd i sin egen container — i ordningen [$container, $rot].
 *
 * @return array{0: Container, 1: Item}
 */
function attlingBrett(): array
{
    $container = Container::factory()->create();

    $rot = attlingItem($container, 'Rot');
    $förstaBarnbarn = null;

    for ($barn = 1; $barn <= 8; $barn++) {
        $barnItem = attlingItem($container, "Barn {$barn}");
        attlingKant($rot, $barnItem);

        for ($barnbarn = 1; $barnbarn <= 8; $barnbarn++) {
            $barnbarnItem = attlingItem($container, "Barnbarn {$barn}.{$barnbarn}");
            attlingKant($barnItem, $barnbarnItem);

            $förstaBarnbarn ??= $barnbarnItem;
        }
    }

    $förälder = $förstaBarnbarn;

    for ($nivå = 1; $nivå <= 10; $nivå++) {
        $barn = attlingItem($container, "Djup {$nivå}");
        attlingKant($förälder, $barn);
        $förälder = $barn;
    }

    return [$container, $rot];
}

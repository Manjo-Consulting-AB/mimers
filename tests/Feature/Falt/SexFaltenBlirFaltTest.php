<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\UsageCounter;
use App\Support\Usage\CountedRows;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\getJson;

/*
 * Issue 420 · De sex fasta fälten blir containerns fält. Se
 * [[ADR-0089 Egna fält på items]] § Riktning 5 och 9 och
 * database/migrations/2026_11_19_020000_move_item_columns_to_fields.php.
 *
 * Filen prövar migreringen mot DATA som redan finns: sviten har redan kört
 * den på en tom databas, så containrar och items som skapas här är exakt det
 * "befintligt" som `up()` ska fylla i. Att anropa `up()` igen är samtidigt
 * idempotensprovet.
 *
 * Hjälparna har prefixet `sexFalt` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

/**
 * En ny instans av migreringen, så `up()` kan köras om i ett test.
 */
function sexFaltMigreringen(): object
{
    return require database_path('migrations/2026_11_19_020000_move_item_columns_to_fields.php');
}

/**
 * @return array{0: Account, 1: Container}
 */
function sexFaltKontext(): array
{
    $konto = Account::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();

    return [$konto, $container];
}

/**
 * Ett item med de sex kolumnerna ifyllda. Kolumnerna är inte längre
 * mass-assignbara (issue 420), så de sätts med `forceFill()` — samma väg en
 * MCP-skrivning tar tills issue 423.
 *
 * @param  array<string, mixed>  $kolumner
 */
function sexFaltItem(Container $container, Account $betalare, array $kolumner = []): Item
{
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_account_id' => $betalare->id,
        'billed_account_id' => $betalare->id,
    ]);

    if ($kolumner !== []) {
        $item->forceFill($kolumner)->save();
    }

    return $item;
}

it('ger varje befintlig container de sex fälten i formulärets ordning', function () {
    [$konto, $container] = sexFaltKontext();

    sexFaltMigreringen()->up();

    $falt = ItemField::query()
        ->where('container_id', $container->id)
        ->orderBy('position')
        ->get();

    expect($falt)->toHaveCount(6)
        ->and($falt->pluck('label')->all())->toBe([
            'Manufacturer',
            'Model',
            'Serial number',
            'Purchase date',
            'Warranty until',
            'Storage location',
        ])
        ->and($falt->pluck('type')->all())->toBe([
            'text', 'text', 'text', 'date', 'date', 'text',
        ])
        ->and($falt->every(fn (ItemField $f): bool => $f->item_id === null))->toBeTrue()
        ->and($falt->pluck('billed_account_id')->unique()->all())->toBe([$konto->id]);
});

it('ger också en container utan items och en i papperskorgen de sex fälten', function () {
    [, $utanItems] = sexFaltKontext();

    [, $slangd] = sexFaltKontext();
    $slangd->delete();

    sexFaltMigreringen()->up();

    foreach ([$utanItems, $slangd] as $container) {
        expect(ItemField::query()->where('container_id', $container->id)->count())->toBe(6);
    }
});

it('kopierar varje icke-tomt värde till item_field_value med itemets betalare', function () {
    [$konto, $container] = sexFaltKontext();

    // En annan betalare än containerns ägare, så provet ser vilken som används.
    $betalare = Account::factory()->create();

    $med = sexFaltItem($container, $betalare, [
        'manufacturer' => 'Victron',
        'serial_number' => 'SN-4711',
        'purchased_at' => '2024-05-17',
        'warranty_until' => null,
        'position_note' => '',
    ]);
    $tomt = sexFaltItem($container, $betalare);

    sexFaltMigreringen()->up();

    $falt = ItemField::query()->where('container_id', $container->id)->get()->keyBy('label');
    $varden = ItemFieldValue::query()->where('item_id', $med->id)->get()->keyBy('item_field_id');

    expect($varden)->toHaveCount(3)
        ->and($varden[$falt['Manufacturer']->id]->value_text)->toBe('Victron')
        ->and($varden[$falt['Serial number']->id]->value_text)->toBe('SN-4711')
        ->and($varden[$falt['Purchase date']->id]->value_date->toDateString())->toBe('2024-05-17')
        // Betalaren är itemets, inte containerns ägare (ADR-0081 § 3).
        ->and($varden[$falt['Manufacturer']->id]->billed_account_id)->toBe($betalare->id);

    // Tomma värden har ingen rad.
    expect(ItemFieldValue::query()->where('item_id', $tomt->id)->count())->toBe(0);
});

it('kopierar värden även för items i papperskorgen', function () {
    [$konto, $container] = sexFaltKontext();

    $slangt = sexFaltItem($container, $konto, ['serial_number' => 'SN-9']);
    $slangt->delete();

    sexFaltMigreringen()->up();

    $falt = ItemField::query()->where('container_id', $container->id)->where('label', 'Serial number')->sole();

    expect(ItemFieldValue::query()
        ->where('item_id', $slangt->id)
        ->where('item_field_id', $falt->id)
        ->value('value_text'))->toBe('SN-9');
});

it('körd två gånger ger inga dubbla fält eller värden', function () {
    [$konto, $container] = sexFaltKontext();
    $item = sexFaltItem($container, $konto, ['manufacturer' => 'Victron', 'serial_number' => 'SN-1']);

    sexFaltMigreringen()->up();
    sexFaltMigreringen()->up();

    expect(ItemField::query()->where('container_id', $container->id)->count())->toBe(6)
        ->and(ItemFieldValue::query()->where('item_id', $item->id)->count())->toBe(2);
});

it('en container som skapas efter migreringen får inga fält', function () {
    [, $ny] = sexFaltKontext();

    // Migreringen har redan kört i svitens uppsättning; containern skapades
    // efteråt och fälten kommer i stället från en mall eller användaren själv
    // (ADR-0089 § Riktning 5). Att köra `up()` igen är inte samma sak — den
    // fyller i containrar som fanns när den kördes.
    expect(ItemField::query()->where('container_id', $ny->id)->count())->toBe(0);
});

it('räknar om metadata_bytes för de konton som berörs', function () {
    [$konto, $container] = sexFaltKontext();
    sexFaltItem($container, $konto, [
        'manufacturer' => 'Victron',
        'serial_number' => 'SN-4711',
        'purchased_at' => '2024-05-17',
    ]);

    sexFaltMigreringen()->up();

    $förväntat = CountedRows::bytesPerAccount()[$konto->id] ?? 0;

    expect((int) UsageCounter::query()->where('account_id', $konto->id)->value('metadata_bytes'))
        ->toBe($förväntat)
        ->and($förväntat)->toBeGreaterThan(0);
});

it('ett sök på ett serienummer hittar itemet', function () {
    [$konto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $item = sexFaltItem($container, $konto, ['serial_number' => 'SN-4711']);

    sexFaltMigreringen()->up();

    $svar = getJson('/api/items?q='.urlencode('SN-4711'), $headers);

    $svar->assertOk();
    expect($svar->json('data.0.ulid'))->toBe($item->ulid);
});

it('ett fält i papperskorgen ger inga sökträffar och värdet kommer tillbaka', function () {
    [$konto, $user, $headers] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $item = sexFaltItem($container, $konto);

    $falt = ItemField::factory()->for($container, 'container')->create([
        'label' => 'Serial number',
        'type' => 'text',
    ]);
    ItemFieldValue::factory()->create([
        'item_id' => $item->id,
        'item_field_id' => $falt->id,
        'value_text' => 'SN-PAPPERSKORG',
        'billed_account_id' => $konto->id,
    ]);

    $sok = fn () => getJson('/api/items?q='.urlencode('SN-PAPPERSKORG'), $headers);

    expect($sok()->json('data'))->toHaveCount(1);

    $falt->delete();

    // Fältet syns inte och dess värde söks inte (ADR-0089 § Riktning 3).
    expect($sok()->json('data'))->toHaveCount(0);

    $falt->restore();

    expect($sok()->json('data'))->toHaveCount(1);
});

it('ingen kod utanför app/Mcp läser de sex kolumnerna', function () {
    $mönster = '/->(?:manufacturer|serial_number|position_note|purchased_at|warranty_until)\b|->model\b/';

    $träffar = [];

    foreach (File::allFiles(app_path()) as $fil) {
        $sokvag = $fil->getPathname();

        if (! str_ends_with($sokvag, '.php') || str_contains($sokvag, DIRECTORY_SEPARATOR.'Mcp'.DIRECTORY_SEPARATOR)) {
            continue;
        }

        $kod = (string) preg_replace('#/\*.*?\*/#s', '', File::get($sokvag));
        $kod = (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);

        if (preg_match_all($mönster, $kod, $match) > 0) {
            $träffar[] = $fil->getRelativePathname().': '.implode(', ', array_unique($match[0]));
        }
    }

    // Frontenden låser samma kolumner med punktnotation.
    $jsMönster = '/\.(?:manufacturer|serial_number|position_note|purchased_at|warranty_until)\b|\.model\b/';

    foreach (File::allFiles(resource_path('js')) as $fil) {
        $kod = File::get($fil->getPathname());

        if (preg_match_all($jsMönster, $kod, $match) > 0) {
            $träffar[] = $fil->getRelativePathname().': '.implode(', ', array_unique($match[0]));
        }
    }

    expect($träffar)->toBe([]);
});

<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerTemplate;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\ItemField;
use App\Models\ItemFieldValue;
use App\Models\ItemVersion;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Issue 1460 · Migreringen 2026_11_15_030000 fyller i `metadata_bytes` med
 * tabellerna och kolumnerna frysta som de såg ut när den skrevs.
 *
 * Före den här issuen läste ifyllningen App\Support\Usage\CountedRows, alltså
 * den levande listan. Varje tabell som lades till senare (item_version,
 * item_field, item_field_value, container_template) och varje kolumn som togs
 * bort (itemets sex fasta fält, issue 425) ändrade då vad en färsk databas
 * fick för `metadata_bytes`. Proven här låser det som gällde 2026-11-15.
 */

function frystMigreringen(): object
{
    return require base_path('database/migrations/2026_11_15_030000_add_metadata_bytes_and_billed_account_id.php');
}

/**
 * @return array{0: Account, 1: User}
 */
function frystKonto(): array
{
    $konto = Account::factory()->create(['type' => 'personal']);
    $användare = User::factory()->create();
    $konto->users()->attach($användare, ['role' => 'owner']);

    return [$konto, $användare];
}

function frystByte(Account $konto): int
{
    return (int) DB::table('usage_counter')->where('account_id', $konto->id)->value('metadata_bytes');
}

it('migreringen importerar inget från App\Support\Usage', function () {
    $källa = file_get_contents(base_path('database/migrations/2026_11_15_030000_add_metadata_bytes_and_billed_account_id.php'));

    // Bara importraderna räknas: klassens docblock får nämna den levande
    // räkningskoden i prosa, men filen får inte anropa den.
    expect(preg_match('/^use App\\\\Support\\\\Usage/m', $källa))->toBe(0);
});

it('ifyllningen ger samma byte som förut för tabellerna som fanns 2026-11-15', function () {
    [$konto, $användare] = frystKonto();

    // Container: name + kind + description + påslag.
    $container = Container::factory()->for($konto, 'account')->create([
        'name' => 'Båten',      // 6 byte (å = 2)
        'kind' => 'boat',       // 4
        'description' => 'abc', // 3
    ]);

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'item',        // 4
        'description' => 'desc', // 4
        'notes' => 'note',       // 4
        'created_by_user_id' => $användare->id,
        'created_by_account_id' => $konto->id,
        'billed_account_id' => $konto->id,
    ]);

    // De sex fasta fältens kolumner räknades 2026-11-15 (issue 420 flyttade
    // först värdena, issue 425 tar bort kolumnerna). De är inte mass-assignable
    // sedan issue 420, så de skrivs förbi modellen.
    DB::table('item')->where('id', $item->id)->update([
        'manufacturer' => 'Acme',    // 4
        'model' => 'X1',             // 2
        'serial_number' => 'SN-1',   // 4
        'position_note' => 'shelf',  // 5
    ]);

    Category::factory()->for($container, 'container')->create(['name' => 'cat', 'billed_account_id' => $konto->id]);         // 3 + 256
    Tag::factory()->for($container, 'container')->create(['name' => 'tag', 'billed_account_id' => $konto->id]);                // 3 + 256
    $schema = Schedule::factory()->for($item, 'item')->create(['title' => 'svc', 'notes' => 'note', 'billed_account_id' => $konto->id]); // 3 + 4 + 256
    ScheduleOccurrence::factory()->for($schema, 'schedule')->create(['completion_note' => 'done', 'billed_account_id' => $konto->id]);   // 4, utan påslag
    CostEntry::factory()->for($item, 'item')->create(['description' => 'oil', 'supplier' => 'shop', 'billed_account_id' => $konto->id]); // 3 + 4 + 256
    Loan::factory()->for($item, 'item')->create(['borrower_name' => 'Ann', 'borrower_email' => 'ann@b.se', 'note' => 'x', 'billed_account_id' => $konto->id]); // 3 + 8 + 1 + 256

    // Tabeller som kom EFTER migreringen: de fanns inte när den skrevs och
    // ska inte påverka en färsk databas ifyllning.
    ItemVersion::factory()->for($item, 'item')->create(['name' => 'gammal', 'billed_account_id' => $konto->id]);
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Rubrik', 'billed_account_id' => $konto->id]);
    ItemFieldValue::factory()->create([
        'item_id' => $item->id,
        'item_field_id' => $fält->id,
        'value_text' => 'värde',
        'billed_account_id' => $konto->id,
    ]);
    ContainerTemplate::factory()->forAccount($konto)->create(['name' => 'mall', 'content' => []]);

    $väntat = (6 + 4 + 3 + 256)      // container
        + (4 + 4 + 4 + 4 + 2 + 4 + 5 + 256) // item, de sex fasta fälten medräknade
        + (3 + 256)                  // category
        + (3 + 256)                  // tag
        + (3 + 4 + 256)              // schedule
        + 4                          // schedule_occurrence
        + (3 + 4 + 256)              // cost_entry
        + (3 + 8 + 1 + 256);         // loan

    frystMigreringen()->backfillMetadataBytes();

    expect(frystByte($konto))->toBe($väntat);
});

it('ifyllningen räknar ingen rad i tabellerna som kom efter migreringen', function () {
    // Ägaren har containern och itemet; den andra har bara rader i de nya
    // tabellerna. Före frysningen hade de räknats på henne.
    [$ägare] = frystKonto();
    [$ny] = frystKonto();
    $container = Container::factory()->for($ägare, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create(['billed_account_id' => $ägare->id]);

    ItemVersion::factory()->for($item, 'item')->create(['name' => 'version', 'billed_account_id' => $ny->id]);
    $fält = ItemField::factory()->for($container, 'container')->create(['label' => 'Rubrik', 'billed_account_id' => $ny->id]);
    ItemFieldValue::factory()->create([
        'item_id' => $item->id,
        'item_field_id' => $fält->id,
        'value_text' => 'värde',
        'billed_account_id' => $ny->id,
    ]);
    ContainerTemplate::factory()->forAccount($ny)->create(['name' => 'mall', 'content' => []]);

    frystMigreringen()->backfillMetadataBytes();

    expect(DB::table('usage_counter')->where('account_id', $ny->id)->exists())->toBeFalse();
});

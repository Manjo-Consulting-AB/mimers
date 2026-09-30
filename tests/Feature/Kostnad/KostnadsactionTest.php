<?php

use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Cost\CreateCostEntry;
use App\Actions\Cost\DeleteCostEntry;
use App\Actions\Cost\ListCostSuppliers;
use App\Actions\Cost\UpdateCostEntry;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/*
 * Issue 168 · Kostnadsraderna i webben — actions. Se
 * app/Actions/Cost/*, [[ADR-0024 Tunna controllers och actions]] och
 * [[ADR-0043 Tre loggar]] § Händelseloggen.
 *
 * **Filen prövar flytten, inte en ny regel.** Logiken bodde i
 * App\Http\Controllers\Api\CostEntryController fram till issue 168, och
 * flyttade till app/Actions/Cost/ när itemets kostnadsflik byggdes: två ytor
 * med samma regler är två ställen att glömma en av dem på. Reglerna är
 * desamma, och att `/api` beter sig oförändrat prövas av KostnadCrudTest och
 * LeverantorTest — de filerna är orörda av issuen och körs som de är.
 *
 * **Provet anropar actionerna direkt**, utan en request emellan: det är
 * actionens kontrakt som prövas, och kontraktet är `handle()`.
 *
 * **Att raden och loggraden ligger i EN transaktion prövas genom att fälla
 * loggskrivningen.** En kontroll av `DB::transactionLevel()` hade bevisat att
 * en transaktion är öppen, inte att raden ligger i den. Här byts
 * App\Actions\Audit\RecordAuditEvent mot en attrapp som kastar, och provet
 * faller om kostnadsraden finns kvar efteråt — den hade gjort det om
 * `save()` stod utanför transaktionen, eller före den.
 *
 * Hjälparna har prefixet `kostnadsaction` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

/**
 * Ett konto med en medlem, en container och ett item under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function kostnadsactionKontext(): array
{
    $konto = Account::factory()->create();
    $anvandare = User::factory()->create();
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create(['currency' => 'SEK']);
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

/**
 * En kropp för CreateCostEntry::handle(), som requesten hade lämnat den.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function kostnadsactionKropp(array $overrides = []): array
{
    return array_merge([
        'incurred_on' => '2026-04-12',
        'amount' => '1200,50',
        'currency' => 'EUR',
        'description' => 'Impeller',
        'supplier' => null,
    ], $overrides);
}

/**
 * Rader i händelseloggen för ett item och en handling.
 *
 * @return Collection<int, stdClass>
 */
function kostnadsactionRader(Item $item, string $action): Collection
{
    return DB::table('audit_log')
        ->where('item_id', $item->id)
        ->where('action', $action)
        ->get();
}

/**
 * En attrapp för händelseloggen som kastar i stället för att skriva.
 *
 * Den binds i containern, så actionen får den när den resolvas — och en rad
 * som överlever att loggskrivningen faller skrevs utanför transaktionen.
 */
function kostnadsactionFallaLoggen(): void
{
    $attrapp = Mockery::mock(RecordAuditEvent::class);
    $attrapp->shouldReceive('handle')->andThrow(new RuntimeException('loggen kunde inte skrivas'));

    app()->instance(RecordAuditEvent::class, $attrapp);
}

it('skapar raden och händelsen i en transaktion', function () {
    [, $anvandare, $container, $item] = kostnadsactionKontext();

    $kostnad = app(CreateCostEntry::class)->handle($container, $item, kostnadsactionKropp(), $anvandare);

    expect($kostnad->exists)->toBeTrue();
    expect($kostnad->item_id)->toBe($item->id);
    expect($kostnad->container_id)->toBe($container->id);
    expect($kostnad->amount)->toBe(120050);
    expect($kostnad->currency)->toBe('EUR');
    expect($kostnad->created_by_user_id)->toBe($anvandare->id);
    expect($kostnad->created_by_account_id)->toBe($container->account_id);

    // Relationen är laddad, så CostEntryResource inte kör en lazy load i
    // `/api`s svar (issue 45a § Beslut 14).
    expect($kostnad->relationLoaded('createdByAccount'))->toBeTrue();

    $rader = kostnadsactionRader($item, AuditLog::ACTION_COST_ENTRY_CREATED);

    expect($rader)->toHaveCount(1);

    $rad = $rader->first();

    expect($rad->user_id)->toBe($anvandare->id);
    expect($rad->container_id)->toBe($container->id);
    expect($rad->subject_type)->toBe('cost_entry');
    expect($rad->subject_id)->toBe($kostnad->ulid);
    expect(json_decode($rad->meta, true))->toBe(['amount' => 120050, 'currency' => 'EUR']);
});

it('rullar tillbaka raden när händelsen inte kan skrivas', function () {
    [, $anvandare, $container, $item] = kostnadsactionKontext();

    kostnadsactionFallaLoggen();

    expect(fn () => app(CreateCostEntry::class)->handle($container, $item, kostnadsactionKropp(), $anvandare))
        ->toThrow(RuntimeException::class);

    expect(CostEntry::query()->count())->toBe(0);
    expect(DB::table('audit_log')->count())->toBe(0);
});

it('ändrar raden och loggar de ändrade fälten, och ingenting när ingenting ändras', function () {
    [, $anvandare, $container, $item] = kostnadsactionKontext();

    $kostnad = app(CreateCostEntry::class)->handle($container, $item, kostnadsactionKropp(), $anvandare);

    app(UpdateCostEntry::class)->handle($item, $kostnad, [
        'amount' => '1500',
        'currency' => 'SEK',
        'description' => 'Ny impeller',
    ], $anvandare);

    $rader = kostnadsactionRader($item, AuditLog::ACTION_COST_ENTRY_UPDATED);

    expect($rader)->toHaveCount(1);

    $meta = json_decode($rader->first()->meta, true);

    // Beloppet och valutan bär gamla och nya värdet; beskrivningen bara sitt
    // namn — den är användarens fritext ([[ADR-0017 Missbruksvektorer]] § 7).
    expect($meta['changed'])->toContain('amount')->toContain('currency')->toContain('description');
    expect($meta['values']['amount'])->toBe(['from' => 120050, 'to' => 150000]);
    expect($meta['values']['currency'])->toBe(['from' => 'EUR', 'to' => 'SEK']);
    expect($meta['values'])->not->toHaveKey('description');
    expect($rader->first()->meta)->not->toContain('Ny impeller');

    expect($kostnad->fresh()->amount)->toBe(150000);
    expect($kostnad->fresh()->currency)->toBe('SEK');

    // En PATCH som inte ändrar något skriver ingen rad: skillnaden mot
    // databasen läses innan raden sparas. Beskrivningen sätts till sitt eget
    // värde, så det är ärligt en ändring som inte ändrar något.
    app(UpdateCostEntry::class)->handle($item, $kostnad->fresh(), [
        'description' => 'Ny impeller',
    ], $anvandare);

    expect(kostnadsactionRader($item, AuditLog::ACTION_COST_ENTRY_UPDATED))->toHaveCount(1);
});

it('rullar tillbaka ändringen när händelsen inte kan skrivas', function () {
    [, $anvandare, $container, $item] = kostnadsactionKontext();

    $kostnad = app(CreateCostEntry::class)->handle($container, $item, kostnadsactionKropp(), $anvandare);

    kostnadsactionFallaLoggen();

    expect(fn () => app(UpdateCostEntry::class)->handle($item, $kostnad, [
        'amount' => '1500',
        'currency' => 'SEK',
    ], $anvandare))->toThrow(RuntimeException::class);

    expect($kostnad->fresh()->amount)->toBe(120050);
    expect($kostnad->fresh()->currency)->toBe('EUR');
});

it('mjukraderar raden och händelsen i en transaktion', function () {
    [, $anvandare, $container, $item] = kostnadsactionKontext();

    $kostnad = app(CreateCostEntry::class)->handle($container, $item, kostnadsactionKropp(), $anvandare);

    app(DeleteCostEntry::class)->handle($item, $kostnad, $anvandare);

    // Mjuk radering: raden ligger kvar med `deleted_at` satt, och den hamnar
    // inte i papperskorgen — den listar fyra typer och behåller fyra.
    expect(CostEntry::query()->count())->toBe(0);
    expect(CostEntry::withTrashed()->whereKey($kostnad->id)->exists())->toBeTrue();

    $rader = kostnadsactionRader($item, AuditLog::ACTION_COST_ENTRY_DELETED);

    expect($rader)->toHaveCount(1);
    expect($rader->first()->subject_id)->toBe($kostnad->ulid);
    expect(json_decode($rader->first()->meta, true))->toBe(['amount' => 120050, 'currency' => 'EUR']);

    // Och den fallna loggen river raderingen, samma väg som skapandet.
    $annan = app(CreateCostEntry::class)->handle($container, $item, kostnadsactionKropp(), $anvandare);

    kostnadsactionFallaLoggen();

    expect(fn () => app(DeleteCostEntry::class)->handle($item, $annan, $anvandare))
        ->toThrow(RuntimeException::class);

    expect($annan->fresh()->deleted_at)->toBeNull();
});

it('listar containerns leverantörer på frekvens', function () {
    [, $anvandare, $container, $item] = kostnadsactionKontext();

    $skapa = app(CreateCostEntry::class);

    foreach ([
        ['supplier' => 'Volvo Penta'],
        ['supplier' => 'Volvo Penta'],
        ['supplier' => 'Biltema'],
        ['supplier' => null],
    ] as $rad) {
        $skapa->handle($container, $item, kostnadsactionKropp($rad), $anvandare);
    }

    $lista = app(ListCostSuppliers::class)->handle($anvandare, $container);

    // Frekvens fallande, namn stigande som andrasortering, och `null` är
    // ingen leverantör.
    expect($lista)->toBe([
        ['supplier' => 'Volvo Penta', 'count' => 2],
        ['supplier' => 'Biltema', 'count' => 1],
    ]);

    // En annan container har sina egna.
    $annan = Container::factory()->for($container->account, 'account')->create();
    $annatItem = Item::factory()->for($annan, 'container')->create();

    $skapa->handle($annan, $annatItem, kostnadsactionKropp(['supplier' => 'West Marine']), $anvandare);

    expect(app(ListCostSuppliers::class)->handle($anvandare, $container))->toBe($lista);
});

it('tillskriver en managed-skribent det mottagande kontot', function () {
    [, , $container, $item] = kostnadsactionKontext();

    // En mottagare UTANFÖR ägarkontot, med en kontobred grant: posten
    // tillskrivs kontot som gav hen åtkomsten, inte containerns ägare.
    $mottagare = User::factory()->create();
    $mottagarKonto = Account::factory()->create();
    $mottagarKonto->users()->attach($mottagare, ['role' => 'member']);

    beviljaAccess($container, $mottagarKonto, 'write', 'managed');

    $kostnad = app(CreateCostEntry::class)->handle($container, $item, kostnadsactionKropp(), $mottagare);

    expect($kostnad->created_by_account_id)->toBe($mottagarKonto->id);
});

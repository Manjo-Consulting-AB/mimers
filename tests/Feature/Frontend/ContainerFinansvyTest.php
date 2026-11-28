<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\ItemTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * Issue 1782 · M99 — containerns Finances-sida: namnet, summan med intäkter,
 * riktnings- och motpartsfiltret, diagrammen och överblickens nettorad. Se
 * [[ADR-0102 Transaktioner på items]] § Riktning 5 och 6.
 *
 * Hjälparna har prefixet `finansvy` — Pest lägger alla testfiler i samma
 * namnrymd.
 */

beforeEach(fn () => withoutVite());

/** @return array{0: User, 1: Container, 2: Item} */
function finansvyKontext(bool $pro = true): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE', 'timezone' => 'UTC']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    if ($pro) {
        Subscription::factory()->for($konto)->for(Plan::where('code', 'pro')->firstOrFail())->create();
    }

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$anvandare, $container, $item];
}

function finansvyRad(Item $item, int $belopp, string $riktning, ?string $motpart = null): ItemTransaction
{
    return ItemTransaction::factory()->for($item, 'item')->create([
        'kind' => $riktning,
        'amount' => $belopp,
        'currency' => 'SEK',
        'incurred_on' => now()->toDateString(),
        'counterparty' => $motpart,
        'created_by_user_id' => $item->created_by_user_id,
        'created_by_account_id' => $item->created_by_account_id,
    ]);
}

function finansvyKalla(string $fil): string
{
    return File::get(resource_path("js/{$fil}"));
}

it('kallar containerns flik och sida Finances och lämnar rutten orörd', function () {
    expect(trans('ui.container.nav.costs', [], 'en'))->toBe('Finances')
        ->and(trans('ui.container.costs.title', [], 'en'))->toBe('Finances')
        ->and(trans('ui.container.costs.heading', [], 'en'))->toBe('Finances')
        ->and(trans('ui.container.overview.costs', [], 'en'))->toBe('Finances');

    [$anvandare, $container] = finansvyKontext();

    actingAs($anvandare)->get("/containers/{$container->ulid}/costs")->assertOk();
});

it('bär utgifter, intäkter och netto i summan, och bara en total utan intäkter i vyn', function () {
    [$anvandare, $container, $item] = finansvyKontext();
    finansvyRad($item, 1000, 'expense');
    finansvyRad($item, 400, 'income');

    $proppar = actingAs($anvandare)->get("/containers/{$container->ulid}/costs")->assertOk()->inertiaProps();

    expect($proppar['costs']['totals'][0])->toMatchArray(['amount' => 1000, 'income' => 400, 'net' => -600]);

    $sida = finansvyKalla('pages/Containers/Costs.vue');

    expect($sida)->toContain('const hasIncome = computed(')
        ->toContain('v-if="hasIncome"')
        ->toContain("t('item.cost.total_expenses')")
        ->toContain("t('item.cost.total_income')")
        ->toContain("t('item.cost.total_net')")
        ->toContain("t('container.costs.total')");
});

it('skickar kind och counterparty från filtret och döljer det för en gratisanvändare', function () {
    $filter = finansvyKalla('components/TransactionFilterBar.vue');

    expect($filter)->toContain('name="kind"')
        ->toContain('params.kind = kind.value')
        ->toContain('params.counterparty = supplier.value')
        ->toContain('name="counterparty"')
        ->toContain("t('container.costs.counterparty')")
        ->toContain('<option value="all">')
        ->toContain('<option value="expense">')
        ->toContain('<option value="income">')
        ->and(finansvyKalla('pages/Containers/Costs.vue'))->toContain('<template v-if="canReport && report">');

    [$gratis, $container, $item] = finansvyKontext(pro: false);
    finansvyRad($item, 100, 'income');

    $proppar = actingAs($gratis)->get("/containers/{$container->ulid}/costs?kind=income")->assertOk()->inertiaProps();

    expect($proppar['report'])->toBeNull()
        ->and($proppar['filterOptions'])->toBeNull()
        ->and($proppar['rows']['total'])->toBe(1);
});

it('låter tabellen följa kind och counterparty, med alla som förval', function () {
    [$anvandare, $container, $item] = finansvyKontext();
    finansvyRad($item, 1000, 'expense', 'Varvet');
    finansvyRad($item, 400, 'income', 'Köparen');

    $hamta = fn (string $fraga) => actingAs($anvandare)
        ->get("/containers/{$container->ulid}/costs{$fraga}")->assertOk()->inertiaProps();

    expect($hamta('')['rows']['total'])->toBe(2)
        ->and($hamta('')['report']['filter']['kind'])->toBe('all')
        ->and($hamta('?kind=income')['rows']['total'])->toBe(1)
        ->and($hamta('?kind=income')['rows']['data'][0]['kind'])->toBe('income')
        ->and($hamta('?kind=expense')['rows']['total'])->toBe(1)
        ->and($hamta('?counterparty=Varvet')['rows']['total'])->toBe(1)
        ->and($hamta('?counterparty=Varvet')['report']['filter']['counterparty'])->toBe('Varvet');
});

it('räknar nedbrytningen bara på utgifter och ger grafen en intäktsserie', function () {
    [$anvandare, $container, $item] = finansvyKontext();
    finansvyRad($item, 1000, 'expense');
    finansvyRad($item, 400, 'income');

    $proppar = actingAs($anvandare)->get("/containers/{$container->ulid}/costs")->assertOk()->inertiaProps();

    expect($proppar['report']['category']['totals'][0]['amount'])->toBe(1000)
        ->and($proppar['report']['period']['groups'][0]['totals'][0])->toMatchArray(['expense' => 1000, 'income' => 400])
        ->and($proppar['costs']['breakdown'][0]['totals'][0]['amount'])->toBe(1000);

    expect(trans('ui.container.costs.breakdown', [], 'en'))->toContain('Expenses')
        ->and(trans('ui.container.costs.chart', [], 'en'))->toContain('Expenses');

    $graf = finansvyKalla('components/TransactionTimeChart.vue');

    expect($graf)->toContain('fill-success')
        ->toContain('chart.hasIncome')
        ->toContain("t('item.cost.total_income')");
});

it('visar nettot under totalen i överblickens panel när containern har intäkter', function () {
    [$anvandare, $container, $item] = finansvyKontext();
    finansvyRad($item, 1000, 'expense');
    finansvyRad($item, 400, 'income');

    $proppar = actingAs($anvandare)->get("/containers/{$container->ulid}")->assertOk()->inertiaProps();

    expect($proppar['costs']['totals'][0]['net'])->toBe(-600);

    $vy = finansvyKalla('pages/Containers/Overview.vue');

    expect($vy)->toContain('v-if="costsHaveIncome"')
        ->toContain("t('container.overview.costs_net')");
});

it('ger valen en träffyta på minst 44 px och ingen vågrät rullning', function () {
    $filter = finansvyKalla('components/TransactionFilterBar.vue');

    expect(substr_count($filter, 'min-h-11'))->toBeGreaterThanOrEqual(7)
        ->and($filter)->not->toContain('overflow-x')
        ->and($filter)->toContain('flex-wrap');
});

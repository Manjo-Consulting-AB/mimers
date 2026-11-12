<?php

use App\Actions\Loan\CreateLoan;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Category;
use App\Models\Container;
use App\Models\CostEntry;
use App\Models\Favorite;
use App\Models\Item;
use App\Models\ItemLink;
use App\Models\Loan;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\StoredFile;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\PendingCommand;

use function Pest\Laravel\artisan;
use function Pest\Laravel\mock;

/*
 * Issue 1107 · `testkonto:skapa`. Kommandot bygger samma testkonto i vilken
 * miljö som helst, genom appens egna actions. Proven kör det genom Artisan med
 * lösenordsfrågorna besvarade och räknar raderna efteråt.
 */

beforeEach(function () {
    Storage::fake('files');
    config(['konton.internal_pro_emails' => ['ny@example.test', 'intern@example.test', 'ett@example.test', 'tva@example.test']]);
});

/**
 * Kör kommandot med ett lösenord som svar på båda frågorna.
 *
 * @param  array<string, mixed>  $flaggor
 */
function testkontoKör(string $email, string $lösenord = 'ett-langt-losenord-123', array $flaggor = []): PendingCommand
{
    return artisan('testkonto:skapa', ['email' => $email] + $flaggor)
        ->expectsQuestion('Lösenord', $lösenord)
        ->expectsQuestion('Lösenord igen', $lösenord);
}

/**
 * @return array<string, int>
 */
function testkontoRader(): array
{
    return [
        'users' => User::query()->count(),
        'accounts' => Account::query()->count(),
        'containers' => Container::withTrashed()->count(),
        'items' => Item::withTrashed()->count(),
        'attachments' => Attachment::withTrashed()->count(),
        'stored' => StoredFile::query()->count(),
    ];
}

it('skapar användaren, containers, items och allt innehåll', function () {
    testkontoKör('ny@example.test')->assertExitCode(0)->run();

    $user = User::query()->where('email', 'ny@example.test')->firstOrFail();
    expect($user->hasVerifiedEmail())->toBeTrue();

    $account = $user->accounts()->wherePivot('role', 'owner')->firstOrFail();
    $containers = Container::query()->where('account_id', $account->id)->orderBy('id')->get();

    expect($containers)->toHaveCount(3);
    expect($containers->pluck('kind')->unique())->toHaveCount(3);
    expect($containers[0]->cover_attachment_id)->not->toBeNull();
    expect($containers[1]->cover_attachment_id)->toBeNull();

    foreach ($containers as $container) {
        expect(Item::query()->where('container_id', $container->id)->count())->toBeGreaterThanOrEqual(10);
        expect(Tag::query()->where('container_id', $container->id)->count())->toBe(3);
        expect(Tag::query()->where('container_id', $container->id)->pluck('color')->unique())->toHaveCount(3);
        expect(Category::query()->where('container_id', $container->id)->whereNull('parent_id')->count())->toBeGreaterThanOrEqual(1);
        expect(Category::query()->where('container_id', $container->id)->whereNotNull('parent_id')->count())->toBeGreaterThanOrEqual(1);

        $medInnehåll = Item::query()->where('container_id', $container->id)->whereNotNull('notes')->first();
        expect($medInnehåll)->not->toBeNull();
        expect($medInnehåll->notes)->toContain('**markdown**');
        expect(Attachment::query()->where('item_id', $medInnehåll->id)->where('kind', 'image')->count())->toBe(1);
        expect(Attachment::query()->where('item_id', $medInnehåll->id)->where('kind', 'document')->count())->toBe(1);
    }

    $itemIds = Item::query()->whereIn('container_id', $containers->pluck('id'))->pluck('id');
    expect($itemIds->count())->toBeGreaterThanOrEqual(30);

    // Träd i två nivåer: ett barn som själv har barn.
    $föräldrar = ItemLink::query()->where('relation', 'parent')->pluck('from_item_id');
    $barnMedBarn = ItemLink::query()->where('relation', 'parent')->whereIn('to_item_id', $föräldrar)->count();
    expect($barnMedBarn)->toBeGreaterThanOrEqual(1);

    expect(ItemLink::query()->where('relation', 'related')->count())->toBe(1);
    expect(Item::query()->whereNotNull('cover_attachment_id')->count())->toBe(1);

    $första = $containers[0];
    expect(Loan::query()->count())->toBe(1);
    expect(Loan::query()->first()->returned_at)->toBeNull();
    expect(CostEntry::query()->where('container_id', $första->id)->count())->toBe(2);
    expect(Schedule::query()->count())->toBe(1);
    expect(ScheduleOccurrence::query()->count())->toBe(1);
    expect(Favorite::query()->where('user_id', $user->id)->count())->toBe(1);
    expect(Item::onlyTrashed()->where('container_id', $första->id)->count())->toBe(1);
    expect(Attachment::onlyTrashed()->count())->toBe(1);
});

it('räknar lagring som summan av bilagornas bytes och håller referensräkningen', function () {
    testkontoKör('ny@example.test')->assertExitCode(0)->run();

    $account = Account::query()->firstOrFail();
    $bilagor = Attachment::withTrashed()->with('storedFile')->get();
    $summa = $bilagor->sum(fn (Attachment $a) => $a->storedFile->byte_size);

    expect($bilagor->count())->toBeGreaterThanOrEqual(8);
    expect((int) DB::table('usage_counter')->where('account_id', $account->id)->value('storage_bytes'))->toBe((int) $summa);

    foreach (StoredFile::query()->get() as $fil) {
        expect($fil->reference_count)->toBe(Attachment::withTrashed()->where('stored_file_id', $fil->id)->count());
    }
});

it('ger internt Pro när adressen står i internal_pro_emails', function () {
    config(['konton.internal_pro_emails' => ['intern@example.test']]);

    testkontoKör('Intern@example.test')->assertExitCode(0)->run();

    $account = User::query()->firstOrFail()->accounts()->firstOrFail();
    expect($account->currentPlan()->code)->toBe('pro');
    expect($account->subscription->external_ref)->toBe('internal');
});

it('ger registreringens plan när adressen inte är intern, och faller på kvoten utan att lämna rader', function () {
    config(['konton.internal_pro_emails' => []]);
    $före = testkontoRader();

    testkontoKör('ny@example.test')->assertExitCode(1)->expectsOutputToContain('quota.containers_exceeded')->run();

    expect(testkontoRader())->toBe($före);
});

it('avvisar en befintlig adress, även med andra versaler, utan att skriva något', function () {
    User::factory()->create(['email' => 'finns@example.test']);
    $före = testkontoRader();

    artisan('testkonto:skapa', ['email' => 'FINNS@Example.test'])->assertExitCode(1)->run();

    expect(testkontoRader())->toBe($före);
});

it('avvisar ett för kort lösenord utan rader', function () {
    $före = testkontoRader();

    testkontoKör('ny@example.test', 'kort')->assertExitCode(1)->run();

    expect(testkontoRader())->toBe($före);
});

it('skriver inget i production när frågan besvaras nej', function () {
    app()->detectEnvironment(fn () => 'production');
    $före = testkontoRader();

    artisan('testkonto:skapa', ['email' => 'ny@example.test'])
        ->expectsConfirmation('Skapa testkontot ny@example.test i production?', 'no')
        ->assertExitCode(1)
        ->run();

    expect(testkontoRader())->toBe($före);
});

it('hoppar över frågan i production med --force', function () {
    app()->detectEnvironment(fn () => 'production');

    testkontoKör('ny@example.test', flaggor: ['--force' => true])->assertExitCode(0)->run();

    expect(User::query()->where('email', 'ny@example.test')->exists())->toBeTrue();
});

it('lämnar ingenting kvar när ett steg faller', function () {
    $före = testkontoRader();
    mock(CreateLoan::class, fn ($mock) => $mock->shouldReceive('handle')->andThrow(new RuntimeException('bom')));

    testkontoKör('ny@example.test')->assertExitCode(1)->run();

    expect(testkontoRader())->toBe($före);
    expect(DB::table('usage_counter')->count())->toBe(0);
});

it('ger samma antal containers, items och bilagor vid två körningar', function () {
    $räkna = function (string $email): array {
        $konto = User::query()->where('email', $email)->firstOrFail()->accounts()->firstOrFail();
        $containers = Container::withTrashed()->where('account_id', $konto->id)->pluck('id');

        return [
            $containers->count(),
            Item::withTrashed()->whereIn('container_id', $containers)->count(),
            Attachment::withTrashed()->whereIn('container_id', $containers)
                ->orWhereIn('item_id', Item::withTrashed()->whereIn('container_id', $containers)->pluck('id'))->count(),
        ];
    };

    testkontoKör('ett@example.test')->assertExitCode(0)->run();
    testkontoKör('tva@example.test')->assertExitCode(0)->run();

    expect($räkna('tva@example.test'))->toBe($räkna('ett@example.test'));
    expect($räkna('ett@example.test')[0])->toBe(3);
});

it('har ett avsnitt Testkontot i Pipeline.md', function () {
    expect(file_get_contents(base_path('docs/Deploy/Pipeline.md')))
        ->toContain("\n## Testkontot\n")
        ->toContain('testkonto:skapa');
});

<?php

use App\Actions\User\DeleteUser;
use App\Actions\User\ReadOverviewPanels;
use App\Models\User;
use App\Support\Frontend\OverviewPanels;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\from;

/*
 * Issue 1112 · Personens ordning för översiktens paneler. Se
 * App\Support\Frontend\OverviewPanels, App\Actions\User\ReadOverviewPanels,
 * App\Actions\User\SaveOverviewPanels, App\Http\Controllers\
 * OverviewPanelController och [[ADR-0073 Översiktens ordning]].
 *
 * Varje "Klart när"-punkt motsvaras av ett test. Hjälparna har prefixet
 * oversikt* för att inte krocka med andra filers globala hjälpare.
 */

function oversiktForval(): array
{
    return [
        ['key' => 'tasks', 'width' => 'narrow'],
        ['key' => 'costs', 'width' => 'narrow'],
        ['key' => 'details', 'width' => 'narrow'],
        ['key' => 'items', 'width' => 'wide'],
        ['key' => 'activity', 'width' => 'narrow'],
    ];
}

function oversiktAndrad(): array
{
    return [
        ['key' => 'activity', 'width' => 'wide'],
        ['key' => 'items', 'width' => 'narrow'],
        ['key' => 'details', 'width' => 'wide'],
        ['key' => 'costs', 'width' => 'narrow'],
        ['key' => 'tasks', 'width' => 'narrow'],
    ];
}

function oversiktRader(User $person): array
{
    return DB::table('overview_panel')->where('user_id', $person->id)
        ->orderBy('position')->get(['panel_key', 'position', 'width'])
        ->map(fn ($r) => (array) $r)->all();
}

function oversiktRad(User $person, string $nyckel, int $position, string $bredd): void
{
    DB::table('overview_panel')->insert([
        'user_id' => $person->id,
        'panel_key' => $nyckel,
        'position' => $position,
        'width' => $bredd,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('migrationen skapar overview_panel med kolumner, uniknyckel, främmande nyckel och CHECK-villkor', function () {
    expect(Schema::hasColumns('overview_panel', ['id', 'user_id', 'panel_key', 'position', 'width', 'created_at', 'updated_at']))->toBeTrue()
        ->and(Schema::hasColumn('overview_panel', 'ulid'))->toBeFalse()
        ->and(Schema::hasColumn('overview_panel', 'deleted_at'))->toBeFalse();

    $person = User::factory()->create();
    oversiktRad($person, 'tasks', 0, 'narrow');

    // Uniknyckeln (user_id, panel_key).
    expect(fn () => oversiktRad($person, 'tasks', 1, 'wide'))->toThrow(QueryException::class);

    // RESTRICT: personen går inte att radera medan raden finns.
    expect(fn () => DB::table('user')->where('id', $person->id)->delete())->toThrow(QueryException::class);

    // CHECK-villkoret läggs bara på mysql, så provet läser migrationen.
    $migration = File::get(base_path('database/migrations/2026_11_12_030000_create_overview_panel_table.php'));
    expect($migration)->toContain("CHECK (width IN ('narrow', 'wide'))")
        ->and($migration)->toContain("->onDelete('restrict')")
        ->and($migration)->toContain("unique(['user_id', 'panel_key'])")
        ->and($migration)->toContain("string('panel_key', 40)")
        ->and($migration)->toContain("unsignedSmallInteger('position')");
});

it('panellistan har de fem nycklarna i förvald ordning och bredd', function () {
    expect(OverviewPanels::keys())->toBe(['tasks', 'costs', 'details', 'items', 'activity'])
        ->and(OverviewPanels::defaultWidth('items'))->toBe('wide')
        ->and(OverviewPanels::defaultWidth('tasks'))->toBe('narrow')
        ->and(OverviewPanels::knows('activity'))->toBeTrue()
        ->and(OverviewPanels::knows('nope'))->toBeFalse();
});

it('ReadOverviewPanels ger förvalet för en person utan rader', function () {
    $person = User::factory()->create();

    expect(app(ReadOverviewPanels::class)->handle($person))->toBe(oversiktForval());
});

it('en giltig PUT sparas och ReadOverviewPanels läser tillbaka samma ordning och bredder', function () {
    $person = User::factory()->create();

    actingAs($person)->from('/dashboard')
        ->put('/settings/overview-panels', ['panels' => oversiktAndrad()])
        ->assertRedirect('/dashboard')
        ->assertSessionHasNoErrors();

    expect(app(ReadOverviewPanels::class)->handle($person))->toBe(oversiktAndrad());
});

it('en ogiltig lista avvisas som valideringsfel och personens rader är oförändrade', function (array $panels) {
    $person = User::factory()->create();
    oversiktRad($person, 'tasks', 0, 'wide');
    $fore = oversiktRader($person);

    actingAs($person)->from('/dashboard')
        ->put('/settings/overview-panels', ['panels' => $panels])
        ->assertRedirect('/dashboard')
        ->assertSessionHasErrors();

    expect(oversiktRader($person))->toBe($fore);
})->with([
    'saknar en nyckel' => [array_slice(oversiktForval(), 0, 4)],
    'okänd nyckel' => [array_replace(oversiktForval(), [4 => ['key' => 'nope', 'width' => 'narrow']])],
    'nyckel två gånger' => [array_replace(oversiktForval(), [4 => ['key' => 'tasks', 'width' => 'narrow']])],
    'okänd bredd' => [array_replace(oversiktForval(), [0 => ['key' => 'tasks', 'width' => 'huge']])],
    'extra post' => [[...oversiktForval(), ['key' => 'tasks', 'width' => 'narrow']]],
    'ingen lista' => [[]],
]);

it('en PUT ersätter personens tidigare rader och rör ingen annan persons rader', function () {
    $person = User::factory()->create();
    $annan = User::factory()->create();
    oversiktRad($person, 'tasks', 0, 'wide');
    oversiktRad($annan, 'costs', 3, 'wide');
    $annansFore = oversiktRader($annan);

    actingAs($person)->put('/settings/overview-panels', ['panels' => oversiktAndrad()])
        ->assertSessionHasNoErrors();

    expect(oversiktRader($person))->toHaveCount(5)
        ->and(array_column(oversiktRader($person), 'panel_key'))->toBe(array_column(oversiktAndrad(), 'key'))
        ->and(oversiktRader($annan))->toBe($annansFore);
});

it('DELETE tar bort personens rader och därefter ger ReadOverviewPanels förvalet', function () {
    $person = User::factory()->create();
    $annan = User::factory()->create();
    oversiktRad($person, 'activity', 0, 'wide');
    oversiktRad($annan, 'activity', 0, 'wide');

    actingAs($person)->from('/dashboard')
        ->delete('/settings/overview-panels')
        ->assertRedirect('/dashboard');

    expect(oversiktRader($person))->toBe([])
        ->and(app(ReadOverviewPanels::class)->handle($person))->toBe(oversiktForval())
        ->and(oversiktRader($annan))->toHaveCount(1);
});

it('en nyckel utan rad läggs sist med förvald bredd och en rad med okänd nyckel hoppas över', function () {
    $person = User::factory()->create();
    oversiktRad($person, 'activity', 0, 'wide');
    oversiktRad($person, 'removed_panel', 1, 'narrow');
    oversiktRad($person, 'tasks', 2, 'wide');

    expect(app(ReadOverviewPanels::class)->handle($person))->toBe([
        ['key' => 'activity', 'width' => 'wide'],
        ['key' => 'tasks', 'width' => 'wide'],
        ['key' => 'costs', 'width' => 'narrow'],
        ['key' => 'details', 'width' => 'narrow'],
        ['key' => 'items', 'width' => 'wide'],
    ]);
});

it('en utloggad begäran mot båda rutterna skickas till /login och skriver ingenting', function () {
    $person = User::factory()->create();
    oversiktRad($person, 'tasks', 0, 'wide');
    $fore = oversiktRader($person);

    from('/dashboard')->put('/settings/overview-panels', ['panels' => oversiktAndrad()])
        ->assertRedirect('/login');
    delete('/settings/overview-panels')->assertRedirect('/login');

    expect(oversiktRader($person))->toBe($fore)
        ->and(DB::table('overview_panel')->count())->toBe(1);
});

it('DeleteUser tar bort personens rader i overview_panel', function () {
    $person = User::factory()->create();
    $annan = User::factory()->create();
    oversiktRad($person, 'tasks', 0, 'narrow');
    oversiktRad($annan, 'tasks', 0, 'narrow');

    app(DeleteUser::class)->handle($person);

    expect(DB::table('overview_panel')->where('user_id', $person->id)->count())->toBe(0)
        ->and(DB::table('overview_panel')->where('user_id', $annan->id)->count())->toBe(1);
});

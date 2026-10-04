<?php

use App\Console\GeneratesTaskNotifications;
use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Notification;
use App\Models\Schedule;
use App\Models\User;
use Illuminate\Support\Carbon;

use function Pest\Laravel\actingAs;

/*
 * M24 · Påminnelsen efter en flytt — att flytta en redan påmind förekomst till
 * ett nytt `due_at` ger en ny påminnelse när den nya tidpunkten inträffar. Se
 * App\Actions\Notification\ReleaseTaskReminders (Beslut 2),
 * App\Actions\Schedule\OpenNextOccurrence::moveOpen() (Beslut 3 och 4) och
 * App\Console\GeneratesTaskNotifications (Beslut 5).
 *
 * Fyndet från testarna 2026-10-03 (docs/Design/findings_20261003_b/): de
 * flyttade en uppgift och fick ingen ny påminnelse. `task.due`/`task.overdue`
 * dedupliceras på `{$type}:{$occurrence->ulid}:{$user->ulid}`, och en flyttad
 * förekomst behåller sin ULID (issue 699 § Beslut 3) — har den redan påmints
 * kommer ingen ny. Flytten frigör därför nycklarna, utan att nyckelns form
 * ändras.
 *
 * **Schemana skapas och ändras via webbrutterna** — samma yta som användaren
 * möter (Delmomentet: `/api`:s PATCH delar UpdateSchedule och får samma
 * beteende utan egna prov). Den första förekomsten öppnas av produktionens egen
 * väg, App\Actions\Schedule\OpenNextOccurrence, i POST:en.
 *
 * **Klockan är fryst** och flyttas inom testen: både `visible_from`-villkoret i
 * generatorn och typen (`task.due` mot `task.overdue`) jämför datum mot
 * användarens dag, så utan en fryst tid vore sviten beroende av kördatumet.
 *
 * Hjälparna har prefixet `paminnelse` — Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-06-15 10:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Ett konto med en medlem, en container och ett item under kontot.
 *
 * @return array{0: Account, 1: User, 2: Container, 3: Item}
 */
function paminnelseKontext(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => $anvandare->id,
        'created_by_account_id' => $konto->id,
    ]);

    return [$konto, $anvandare, $container, $item];
}

function paminnelseSchemaUrl(Container $container, Item $item): string
{
    return "/containers/{$container->ulid}/items/{$item->ulid}/schedules";
}

/**
 * Skapar ett schema via webben (`containers.items.schedules.store`) — samma väg
 * som användaren, så att den första förekomsten öppnas av
 * App\Actions\Schedule\OpenNextOccurrence. Grundschemat är issue 700:s:
 * `interval`, `month`, 12, `anchor_date` 2026-06-15, `lead_days` 0.
 *
 * @param  array<string, mixed>  $overrides
 */
function paminnelseSkapa(User $anvandare, Container $container, Item $item, array $overrides = []): Schedule
{
    actingAs($anvandare)
        ->post(paminnelseSchemaUrl($container, $item), array_merge([
            'title' => 'Byt olja',
            'recurrence_type' => 'interval',
            'interval_unit' => 'month',
            'interval_count' => 12,
            'anchor_date' => '2026-06-15',
            'lead_days' => 0,
        ], $overrides))
        ->assertRedirect();

    return Schedule::query()->orderByDesc('id')->firstOrFail();
}

/**
 * Ändrar schemat via webben (`containers.items.schedules.update`).
 *
 * @param  array<string, mixed>  $kropp
 */
function paminnelsePatch(User $anvandare, Container $container, Item $item, Schedule $schema, array $kropp): void
{
    actingAs($anvandare)
        ->patch(paminnelseSchemaUrl($container, $item)."/{$schema->ulid}", $kropp)
        ->assertRedirect();
}

/**
 * Kör generatorn precis som schemaläggningen gör.
 */
function paminnelseKor(): void
{
    app(GeneratesTaskNotifications::class)->handle();
}

/*
 * Klart när: körning ger en task.due; PATCH anchor_date 2026-07-01, lead_days 2;
 * klockan till 2026-06-29, körning: två task.due med samma subject_id, den nya
 * med payload.date 2026-07-01 och nyckeln satt, den gamla med nyckeln null.
 */
it('påminner på nytt när en påmind förekomst flyttats och nya tidpunkten inträffar', function () {
    [, $anvandare, $container, $item] = paminnelseKontext();

    $schema = paminnelseSkapa($anvandare, $container, $item);
    $förekomst = $schema->openOccurrence()->sole();

    paminnelseKor();

    expect(Notification::query()->count())->toBe(1);

    $gammal = Notification::query()->sole();

    expect($gammal->type)->toBe(Notification::TYPE_TASK_DUE);
    expect($gammal->payload['date'])->toBe('2026-06-15');
    expect($gammal->dedupe_key)->toBe('task.due:'.$förekomst->ulid.':'.$anvandare->ulid);

    paminnelsePatch($anvandare, $container, $item, $schema, [
        'anchor_date' => '2026-07-01',
        'lead_days' => 2,
    ]);

    // Samma rad: flytten behåller ULID:n (issue 699 § Beslut 3). Den gamla
    // notisen står kvar — bara nyckeln är frigjord.
    expect($schema->openOccurrence()->sole()->ulid)->toBe($förekomst->ulid);
    expect($gammal->fresh()->dedupe_key)->toBeNull();
    expect(Notification::query()->count())->toBe(1);

    Carbon::setTestNow('2026-06-29 10:00:00');

    paminnelseKor();

    $notiser = Notification::query()->orderBy('id')->get();

    expect($notiser)->toHaveCount(2);
    expect($notiser->pluck('type')->unique()->all())->toBe([Notification::TYPE_TASK_DUE]);
    expect($notiser->pluck('subject_id')->unique()->all())->toBe([$förekomst->id]);

    $ny = $notiser->last();

    expect($ny->payload['date'])->toBe('2026-07-01');
    expect($ny->dedupe_key)->toBe('task.due:'.$förekomst->ulid.':'.$anvandare->ulid);
    expect($notiser->first()->dedupe_key)->toBeNull();
});

/*
 * Klart när: påmind; PATCH bara title, sedan bara lead_days 3; körning:
 * fortfarande en notis, nyckeln orörd. En ändrad `lead_days` flyttar
 * `visible_from` men lämnar förfallet (Beslut 4).
 */
it('påminner inte en gång till när förekomsten inte flyttats', function () {
    [, $anvandare, $container, $item] = paminnelseKontext();

    $schema = paminnelseSkapa($anvandare, $container, $item);
    $förekomst = $schema->openOccurrence()->sole();

    paminnelseKor();

    $nyckel = Notification::query()->sole()->dedupe_key;

    // Bara titeln: varken due_at eller visible_from rörs.
    paminnelsePatch($anvandare, $container, $item, $schema, ['title' => 'Byt olja och filter']);
    // Bara lead_days: visible_from flyttas till 2026-06-12, förfallet står kvar.
    paminnelsePatch($anvandare, $container, $item, $schema, ['lead_days' => 3]);

    $oförändrad = $schema->openOccurrence()->sole();

    expect($oförändrad->due_at->toDateString())->toBe('2026-06-15');
    expect($oförändrad->visible_from->toDateString())->toBe('2026-06-12');

    paminnelseKor();

    $notis = Notification::query()->sole();

    expect($notis->dedupe_key)->toBe($nyckel);
    expect($notis->subject_id)->toBe($förekomst->id);
    expect($notis->payload['date'])->toBe('2026-06-15');
});

/*
 * Klart när: påmind; PATCH anchor_date 2026-07-01, lead_days 2; körning samma
 * dag och på 2026-06-28: fortfarande en notis. Den frigjorda förekomsten
 * påminns först när nya `visible_from` (2026-06-29) passerats (Beslut 5).
 */
it('påminner inte om en flyttad förekomst innan nya tidpunkten', function () {
    [, $anvandare, $container, $item] = paminnelseKontext();

    $schema = paminnelseSkapa($anvandare, $container, $item);

    paminnelseKor();
    expect(Notification::query()->count())->toBe(1);

    paminnelsePatch($anvandare, $container, $item, $schema, [
        'anchor_date' => '2026-07-01',
        'lead_days' => 2,
    ]);

    // Samma dag som flytten: visible_from är 2026-06-29, fortfarande framåt.
    paminnelseKor();
    expect(Notification::query()->count())->toBe(1);

    Carbon::setTestNow('2026-06-28 10:00:00');
    paminnelseKor();
    expect(Notification::query()->count())->toBe(1);
});

/*
 * Klart när: anchor_date 2026-06-10: en task.overdue; PATCH anchor_date
 * 2026-06-12; körning: två task.overdue. Båda ligger i det förflutna, så typen
 * följer klockan och inte ett nytt datum.
 */
it('ger en ny försenad-notis när en försenad förekomst flyttas till ett annat passerat datum', function () {
    [, $anvandare, $container, $item] = paminnelseKontext();

    $schema = paminnelseSkapa($anvandare, $container, $item, ['anchor_date' => '2026-06-10']);
    $förekomst = $schema->openOccurrence()->sole();

    paminnelseKor();

    expect(Notification::query()->sole()->type)->toBe(Notification::TYPE_TASK_OVERDUE);
    expect(Notification::query()->sole()->payload['date'])->toBe('2026-06-10');

    paminnelsePatch($anvandare, $container, $item, $schema, ['anchor_date' => '2026-06-12']);

    paminnelseKor();

    $notiser = Notification::query()->orderBy('id')->get();

    expect($notiser)->toHaveCount(2);
    expect($notiser->pluck('type')->unique()->all())->toBe([Notification::TYPE_TASK_OVERDUE]);
    expect($notiser->pluck('subject_id')->unique()->all())->toBe([$förekomst->id]);
    expect($notiser->last()->payload['date'])->toBe('2026-06-12');
    expect($notiser->last()->dedupe_key)->toBe('task.overdue:'.$förekomst->ulid.':'.$anvandare->ulid);
    expect($notiser->first()->dedupe_key)->toBeNull();
});

/*
 * Klart när: två scheman, båda påminda, det ena flyttas: det andras dedupe_key
 * är oförändrad. Frigöringen är avgränsad till subjektet — förekomstens eget
 * `subject_id` (Beslut 2).
 */
it('rör inte en annan förekomsts nycklar', function () {
    [, $anvandare, $container, $item] = paminnelseKontext();

    $första = paminnelseSkapa($anvandare, $container, $item, ['title' => 'Byt olja']);
    $andra = paminnelseSkapa($anvandare, $container, $item, ['title' => 'Byt impeller']);

    paminnelseKor();

    expect(Notification::query()->count())->toBe(2);

    $andraFörekomst = $andra->openOccurrence()->sole();
    $orördNyckel = Notification::query()->where('subject_id', $andraFörekomst->id)->sole()->dedupe_key;

    expect($orördNyckel)->toBe('task.due:'.$andraFörekomst->ulid.':'.$anvandare->ulid);

    paminnelsePatch($anvandare, $container, $item, $första, ['anchor_date' => '2026-08-01']);

    // Den flyttade förekomstens nyckel är frigjord ...
    $förstaFörekomst = $första->openOccurrence()->sole();

    expect(Notification::query()->where('subject_id', $förstaFörekomst->id)->sole()->dedupe_key)->toBeNull();

    // ... och den andra förekomstens står orörd.
    expect(Notification::query()->where('subject_id', $andraFörekomst->id)->sole()->dedupe_key)->toBe($orördNyckel);
});

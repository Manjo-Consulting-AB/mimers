<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\Item;
use App\Models\Schedule;
use App\Models\ScheduleOccurrence;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\withoutVite;

/*
 * M32 · issue 287 — uppgiftspanelen får sina ikonrutor, se
 * resources/js/components/ContainerTasksPanel.vue,
 * resources/js/components/TodoRow.vue, app/Actions/Schedule/ListTodo::row(),
 * [[ADR-0057 Ikoner och typsnitt]] § 3 och [[M32 Formspråket]] § 287.
 *
 * **Rutan ritas i webbläsaren och går inte att rendera här.** Det som går att
 * avgöra på serversidan är att panelen slår på rutan på varje rad, att
 * `TodoRow` ritar `UiIcon` med `tile` när proppen är satt, att rollen väljs ur
 * schemats återkommetyp och tonen ur datumregeln — och att raden faktiskt bär
 * flaggan som rollen läses ur. Kommentarerna rensas bort först, så en mening i
 * ett docblock inte kan nöja en regel — samma grepp som GenomgangTest och
 * ItemradTest.
 *
 * **Panelen räknar ingen ton.** Tonen bor i datumregeln, och provet fäller ett
 * `useRelativeDate`-anrop eller en egen `danger`/`warning`-gren i panelen:
 * två uppfattningar om samma rad hade glidit isär, och den ena hade färgat
 * rutan annat än datumet.
 *
 * Hjälparna har prefixet `uppgiftspanelIkon` — Pest lägger alla testfiler i
 * samma namnrymd när hela sviten körs.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::today()->setTime(12, 0));
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Källkoden med kommentarer borta: block-, HTML- och radkommentarer.
 */
function uppgiftspanelIkonUtanKommentarer(string $kod): string
{
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/**
 * En fil ur resources/js, relativt katalogen, med kommentarer borta.
 */
function uppgiftspanelIkonKod(string $sokvag): string
{
    return uppgiftspanelIkonUtanKommentarer(File::get(resource_path("js/{$sokvag}")));
}

/**
 * Ett ägarkonto med en medlem i, samma form som översiktsproven använder.
 *
 * @return array{0: Account, 1: User}
 */
function uppgiftspanelIkonKonto(): array
{
    $konto = Account::factory()->create(['locale' => 'sv_SE']);
    $anvandare = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($anvandare, ['role' => 'owner']);

    return [$konto, $anvandare];
}

/**
 * En container under $konto, med ett bestämt namn så panelen går att läsa.
 */
function uppgiftspanelIkonParm(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create(['name' => 'Havsörnen']);
}

/**
 * Ett item i containern, med kända `created_by_*`.
 */
function uppgiftspanelIkonItem(Container $container): Item
{
    return Item::factory()->for($container, 'container')->create([
        'name' => 'Motorn',
        'created_by_user_id' => User::factory()->create()->id,
        'created_by_account_id' => $container->account_id,
    ]);
}

/**
 * En öppen uppgift med känd förfallodag och återkommetyp.
 *
 * Raden byggs direkt i stället för genom App\Actions\Schedule\
 * OpenNextOccurrence, av samma skäl som i ContainerOversiktTest: förfallodagen
 * ska vara känd utan att räkna kalender.
 */
function uppgiftspanelIkonUppgift(Item $item, string $due, string $titel, string $recurrence): void
{
    $schema = Schedule::factory()->for($item, 'item')->create([
        'title' => $titel,
        'recurrence_type' => $recurrence,
        'interval_unit' => $recurrence === 'none' ? null : 'month',
        'interval_count' => $recurrence === 'none' ? null : 12,
        'anchor_date' => $due,
        'lead_days' => 0,
        'is_active' => true,
    ]);

    ScheduleOccurrence::factory()->create([
        'schedule_id' => $schema->id,
        'due_at' => $due,
        'visible_from' => $due,
        'status' => 'open',
    ]);
}

/*
 * Klart när: varje rad bär en `UiIcon` med `tile`.
 *
 * Panelen slår på rutan på VARJE rad och räknar ingenting själv. Rollen och
 * tonen räknas i `TodoRow`, där datumet redan finns — en panel som räknade
 * dem hade varit en andra uppfattning om samma rad.
 */
it('slår på ikonrutan på varje rad i panelen', function () {
    $panelen = uppgiftspanelIkonKod('components/ContainerTasksPanel.vue');

    expect($panelen)->toMatch(
        '~<TodoRow\b[^>]*v-for="task in props\.tasks"[^>]*\bicon-tile\b~s',
        'ContainerTasksPanel slår inte på ikonrutan på raden',
    );
});

/*
 * Klart när: tonen kommer ur `useRelativeDate` och räknas inte i panelen.
 *
 * Panelen känner varken datumregeln eller tonerna. Slår den upp `dueDate()`
 * eller skriver `danger`/`warning` själv har den fått en egen uppfattning om
 * radens datum, och då kan rutan och datumtexten säga olika saker.
 */
it('räknar ingen ton i panelen', function () {
    $panelen = uppgiftspanelIkonKod('components/ContainerTasksPanel.vue');

    expect($panelen)->not->toContain('useRelativeDate');
    expect($panelen)->not->toContain('dueDate(');
    expect($panelen)->not->toMatch('/\b(danger|warning|neutral)\b/');
});

/*
 * Klart när: raden ritar `UiIcon` med `tile` när rutan är påslagen.
 *
 * `UiIcon` äger storlek, linjebredd, färg och `aria-hidden` (issue 282), och
 * rutan är en propp på samma komponent ([[ADR-0057 Ikoner och typsnitt]] § 2).
 * Tonen är datumregelns `due.state`, alltså samma värde som datumtexten
 * färgas med.
 */
it('ritar UiIcon med rutan på raden', function () {
    $raden = uppgiftspanelIkonKod('components/TodoRow.vue');

    expect($raden)->toContain("import UiIcon from './UiIcon.vue'")
        ->toContain('iconTile');

    expect($raden)->toMatch(
        '~<UiIcon[^>]*:name="iconRole"[^>]*:tile="due\.state"~s',
        'TodoRow ritar inte UiIcon med roll och datumregelns ton',
    );
});

/*
 * Klart när: rollen är `schedule` för ett återkommande schema och `task` för
 * resten.
 *
 * Flaggan räknas på servern ur `recurrence_type`: `fixed` och `interval`
 * återkommer, `none` gör det inte. Raden jämför aldrig återkommetypen själv,
 * och båda rollerna finns i den slutna ikonlistan.
 */
it('ger ett återkommande schema schedule och resten task', function () {
    $raden = uppgiftspanelIkonKod('components/TodoRow.vue');
    $listan = uppgiftspanelIkonUtanKommentarer(
        File::get(base_path('app/Actions/Schedule/ListTodo.php')),
    );

    expect($raden)->toContain("props.entry.recurring ? 'schedule' : 'task'")
        ->and($listan)->toContain("'recurring' => \$occurrence->schedule->recurrence_type !== 'none'");

    $ikoner = uppgiftspanelIkonKod('icons/index.js');

    expect($ikoner)->toContain('task: [')
        ->toContain('schedule: [');
});

/*
 * Klart när: tonen kommer ur `useRelativeDate`.
 *
 * Raden frågar datumregeln — den jämför inga dagar själv — och rutan färgas
 * med svaret. `DATE_TONES` är den enda platsen som översätter regelns tre
 * tillstånd till klassnamn.
 */
it('tar tonen ur datumregeln', function () {
    $raden = uppgiftspanelIkonKod('components/TodoRow.vue');

    expect($raden)->toContain('dueDate(props.entry.due_at, props.entry.overdue)')
        ->toContain("danger: 'text-danger'")
        ->toContain("warning: 'text-warning'")
        ->toContain("neutral: 'text-ink-muted'")
        ->toContain('DATE_TONES[due.value.state]');
});

/*
 * Klart när: rutan och datumtexten har alltid samma ton.
 *
 * Båda läser `due.state` — rutan som `:tile`, datumet genom `dateTone` — så
 * de två kan inte glida ifrån varandra. Utan rutan behåller raden sin gamla
 * ton, eftersom de andra ytorna inte har fått ikoner än.
 */
it('ger datumtexten samma ton som rutan', function () {
    $raden = uppgiftspanelIkonKod('components/TodoRow.vue');

    expect($raden)->toMatch(
        '~<time\b[^>]*:class="dateTone"~s',
        'datumtexten färgas inte ur samma ton som rutan',
    );

    expect($raden)->toMatch(
        '~const dateTone = computed\(\(\) => \{.*?due\.value\.state.*?\}\)~s',
        'dateTone läser inte datumregeln',
    );
});

/*
 * Klart när: raden bär flaggan rollen läses ur.
 *
 * `recurring` räknas i `ListTodo::row()` och läggs BREDVID
 * `TodoEntryResource` — `/api` har inte bett om den. En `interval`-rad
 * återkommer och en `none`-rad gör det inte.
 */
it('märker en återkommande rad och lämnar en engångsrad omarkerad', function () {
    withoutVite();

    [$konto, $anvandare] = uppgiftspanelIkonKonto();
    $container = uppgiftspanelIkonParm($konto);
    $item = uppgiftspanelIkonItem($container);

    uppgiftspanelIkonUppgift($item, Carbon::today()->addDays(3)->toDateString(), 'Återkommande', 'interval');
    uppgiftspanelIkonUppgift($item, Carbon::today()->addDays(5)->toDateString(), 'Engång', 'none');

    $svar = actingAs($anvandare)->get("/containers/{$container->ulid}")->assertOk();

    $rader = collect($svar->inertiaProps()['tasks'])->keyBy('schedule.title');

    expect($rader['Återkommande']['recurring'])->toBeTrue()
        ->and($rader['Engång']['recurring'])->toBeFalse();
});

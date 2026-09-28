<?php

use App\Models\Account;
use App\Models\Container;
use App\Models\LegalHold;
use App\Models\User;
use App\Models\UserDeletion;
use App\Notifications\UserDeletionConfirmationNotification;
use App\Support\User\DeletionBlocker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\from;
use function Pest\Laravel\withoutVite;

/*
 * Issue 145 · Personraderingens yta i inställningarna. Se
 * app/Http/Controllers/Settings/UserDeletionController.php,
 * app/Actions/User/RequestUserDeletion.php,
 * resources/js/components/UserDeletionForm.vue,
 * [[M22 Redo för testare]] § 145 och [[ADR-0045 Radering av konto och
 * person]] § Beslut 3.
 *
 * **Två anrop, och det första är det här filens:** POST
 * /settings/delete-user begär, och lämnar `user`-raden orörd. Länken i mejlet
 * — bekräftelsesidan och raderingen — prövas i
 * tests/Feature/Konto/PersonraderingslankTest.php sedan 2026-09-28, då den
 * vägen slutade kräva inloggning ([[ADR-0045 Radering av konto och person]]
 * § Uppföljning 2026-09-28). Hjälparna `raderingsyta*` nedan används av båda
 * filerna.
 *
 * Själva raderingen — kontona, författarkolumnerna, loggraden — prövas i
 * tests/Feature/Konto/PersonraderingTest.php (issue 144). Den här filen prövar
 * ytan ovanpå den: att rätt sak händer vid rätt tillfälle, och att ingenting
 * händer när det inte ska.
 *
 * Hjälparna heter raderingsyta* för att inte krocka med de globala i
 * tests/Support/Testhjalpare.php eller med personradering* i
 * PersonraderingTest — Pests funktioner är globala och hela sviten körs i en
 * process.
 */

/**
 * Ett konto med personen som enda medlem — kontot som raderas.
 */
function raderingsytaEgetKonto(User $person): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    return $konto;
}

/**
 * Ett konto med personen och en annan medlem — kontot som lämnas.
 */
function raderingsytaDelatKonto(User $person): Account
{
    $konto = Account::factory()->create();
    $konto->users()->attach($person, ['role' => 'member']);
    $konto->users()->attach(User::factory()->create(), ['role' => 'owner']);

    return $konto;
}

function raderingsytaContainer(Account $konto): Container
{
    return Container::factory()->for($konto, 'account')->create();
}

/**
 * Länken ur mejlet till kontots egen adress. Tokenet finns bara där — svaret
 * på POST bär det aldrig.
 */
function raderingsytaLänk(User $person): string
{
    $url = null;

    Notification::assertSentTo(
        $person,
        UserDeletionConfirmationNotification::class,
        function (UserDeletionConfirmationNotification $notis) use (&$url): bool {
            $url = $notis->url;

            return true;
        }
    );

    if ($url === null) {
        throw new RuntimeException('Ingen bekräftelselänk hittades.');
    }

    return $url;
}

/**
 * Begär en radering och returnerar länken ur mejlet. Kastar om begäran inte
 * gick igenom — varje prov som använder hjälparen förväntar sig ett lyckat
 * utskick, och ett tyst misslyckande hade gjort provet grönt av fel skäl.
 *
 * @param  array<string, string>  $overrides
 */
function raderingsytaBegär(User $person, array $overrides = []): string
{
    from('/settings/security')
        ->post('/settings/delete-user', $overrides)
        ->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'user-deletion-requested');

    return raderingsytaLänk($person);
}

// --- Sidan ---------------------------------------------------------------

it('visar vilka konton som raderas och vilka som lämnas', function () {
    withoutVite();

    $person = User::factory()->create();
    $eget = raderingsytaEgetKonto($person);
    $delat = raderingsytaDelatKonto($person);

    actingAs($person)
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Security')
            ->where('deletion.accountsToDelete', [
                ['ulid' => $eget->ulid, 'name' => $eget->name],
            ])
            ->where('deletion.accountsToLeave', [
                ['ulid' => $delat->ulid, 'name' => $delat->name],
            ])
            ->where('deletion.blockers', []));
});

it('visar spärren med kontot och containern den gäller', function () {
    withoutVite();

    $person = User::factory()->create();
    $konto = raderingsytaEgetKonto($person);
    $container = raderingsytaContainer($konto);

    beviljaAccess($container, Account::factory()->create(), 'read', 'managed');

    actingAs($person)
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('deletion.blockers.0.code', DeletionBlocker::CODE_SHARED_CONTAINER)
            // Namnen och inte ULID:erna: spärren kommer som en identifierare
            // och blir läsbar först här.
            ->where('deletion.blockers.0.account', $konto->name)
            ->where('deletion.blockers.0.containers', [$container->name]));
});

/*
 * Den rättsliga spärren bär ingen data alls, och det är hela poängen
 * (DeletionBlocker::legalHold()): att ett konto är spärrat är i sig en uppgift
 * om en pågående utredning, och varken vyn eller sidpropen får röja vilket
 * konto det gäller.
 */
it('avslöjar ingenting om vilket konto en rättslig spärr gäller', function () {
    withoutVite();

    $person = User::factory()->create();
    $konto = raderingsytaEgetKonto($person);

    LegalHold::factory()->create(['account_id' => $konto->id]);

    actingAs($person)
        ->get('/settings/security')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('deletion.blockers.0.code', DeletionBlocker::CODE_LEGAL_HOLD)
            ->where('deletion.blockers.0.account', null)
            ->where('deletion.blockers.0.containers', []));
});

it('stänger av knappen när något spärrar', function () {
    $vy = File::get(resource_path('js/components/UserDeletionForm.vue'));

    // En avstängd knapp hindrar ett klick men inte Enter i ett fält — därför
    // vaktar submit() också. Båda raderna prövas: utan den andra vore
    // "begäran går inte att göra" falskt för den som tabbar sig fram.
    expect($vy)->toContain('const blocked = computed(() => props.blockers.length > 0)')
        ->and($vy)->toContain(':disabled="blocked || form.processing"')
        ->and($vy)->toContain('if (blocked.value) {');
});

// --- Begäran -------------------------------------------------------------

it('raderar ingenting när begäran tas emot', function () {
    Notification::fake();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    raderingsytaBegär($person);

    // Personen finns kvar: begäran skickar bara mejlet, och raderingen sker
    // först när länken öppnas. Det är hela tvåstegsflödet, och det finns
    // ingen ångerfrist som fångar en tidig radering.
    expect(User::query()->whereKey($person->id)->exists())->toBeTrue()
        ->and(UserDeletion::query()->where('user_id', $person->id)->count())->toBe(1);
});

it('mejlar bekräftelselänken till kontots adress', function () {
    Notification::fake();

    $person = User::factory()->create(['email' => 'person@exempel.se']);
    raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    Notification::assertSentTo(
        $person,
        UserDeletionConfirmationNotification::class,
        // Adressen är användarens: notisen bär ingen mottagare själv, och
        // kanalen är `mail` — alltså den adress kontot har.
        fn (UserDeletionConfirmationNotification $notis): bool => $notis->via($person) === ['mail']
    );

    // Transaktionellt utskick: ingen rad i `notification`.
    expect(DB::table('notification')->count())->toBe(0)
        ->and($länk)->toContain('/settings/delete-user/');
});

/*
 * Mejlet renderas på riktigt och inte bara fångas av faken: `toMail()` körs
 * aldrig när notisen är faked, så en nyckel som saknas i `notiser.php` hade
 * gått tyst förbi.
 */
it('renderar bekräftelsemejlet med länken och vad som raderas', function () {
    Notification::fake();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    $innehåll = null;
    $ämne = null;

    Notification::assertSentTo(
        $person,
        UserDeletionConfirmationNotification::class,
        function (UserDeletionConfirmationNotification $notis, array $kanaler, object $mottagare) use (&$innehåll, &$ämne, $person): bool {
            $meddelande = $notis->toMail($person);

            $ämne = $meddelande->subject;
            $innehåll = (string) $meddelande->render();

            return $kanaler === ['mail'] && $mottagare->is($person);
        }
    );

    // Länken står i mejlet — det är den ENDA plats klartext-tokenet finns.
    expect($innehåll)->toContain($länk)
        ->and($innehåll)->toContain(trans('notiser.user_deletion.confirm.action', [], 'en'))
        // Löftet flödet vilar på: den som tror att formuläret redan raderat
        // stänger mejlet och undrar varför hon är kvar.
        ->and($innehåll)->toContain('Nothing is deleted until you open the link')
        // Ordet: det är personen och de egna kontona, inte kontot hon delar.
        ->and($innehåll)->toContain('every account where you are the only member')
        ->and($ämne)->toBe(trans('notiser.user_deletion.confirm.subject', [], 'en'))
        ->and($ämne)->not->toBe('notiser.user_deletion.confirm.subject');
});

it('vägrar begäran utan giltig kod när tvåfaktorn är på', function () {
    Notification::fake();

    [$person, $secret] = användareMedBekräftadTotp();
    raderingsytaEgetKonto($person);

    actingAs($person);

    from('/settings/security')
        ->post('/settings/delete-user', [])
        ->assertRedirect('/settings/security')
        ->assertSessionHasErrors('code');

    expect(UserDeletion::query()->where('user_id', $person->id)->count())->toBe(0)
        ->and(User::query()->whereKey($person->id)->exists())->toBeTrue();

    Notification::assertNothingSent();

    // Och med en giltig kod går samma begäran igenom.
    from('/settings/security')
        ->post('/settings/delete-user', ['code' => totpKodFör($secret)])
        ->assertRedirect('/settings/security')
        ->assertSessionHas('status', 'user-deletion-requested');

    expect(UserDeletion::query()->where('user_id', $person->id)->count())->toBe(1);
});

// --- Komponenten ----------------------------------------------------------

/*
 * Klart när: säkerhetssidan visar listan och spärrarna som förut, nu genom
 * UserDeletionSummary.vue. Provet läser filerna och inte en rendering: det
 * som ska vara sant är att listorna ritas på ETT ställe och används av båda
 * ytorna, och det syns bara i källkoden.
 */
it('ritar listan och spärrarna med UserDeletionSummary', function () {
    $formulär = File::get(resource_path('js/components/UserDeletionForm.vue'));
    $bekräftelse = File::get(resource_path('js/pages/Settings/ConfirmUserDeletion.vue'));
    $sammanfattning = File::get(resource_path('js/components/UserDeletionSummary.vue'));

    expect($formulär)->toContain("import UserDeletionSummary from './UserDeletionSummary.vue'")
        ->and($formulär)->toContain('<UserDeletionSummary')
        // Spärrens rubrik och mening är formulärets egna: det är BEGÄRAN som
        // inte kan göras, och bekräftelsesidan säger samma sak om raderingen.
        ->and($formulär)->toContain('settings.security.deletion.blocked')
        // Och den gemensamma komponenten används av båda ytorna.
        ->and($bekräftelse)->toContain("import UserDeletionSummary from '../../components/UserDeletionSummary.vue'")
        ->and($bekräftelse)->toContain('<UserDeletionSummary')
        // Klart när: spärren stänger av knappen på bekräftelsesidan. En
        // avstängd knapp hindrar ett klick men inte Enter i ett fält, och
        // därför vaktar submit() också — samma två rader som i formuläret.
        ->and($bekräftelse)->toContain('const blocked = computed(() => props.deletion.blockers.length > 0)')
        ->and($bekräftelse)->toContain(':disabled="blocked || form.processing"')
        ->and($bekräftelse)->toContain('if (blocked.value) {');

    // Listorna står i sammanfattningen och ingen annanstans — en avskrift i
    // någon av ytorna hade varit en andra sanning om vad raderingen gör.
    expect($sammanfattning)->toContain('settings.security.deletion.accounts_deleted_heading')
        ->and($sammanfattning)->toContain('settings.security.deletion.accounts_left_heading')
        ->and($formulär)->not->toContain('accounts_deleted_heading')
        ->and($bekräftelse)->not->toContain('accounts_deleted_heading');
});

// --- Raden ---------------------------------------------------------------

it('lagrar aldrig tokenet i klartext', function () {
    Notification::fake();

    $person = User::factory()->create();
    raderingsytaEgetKonto($person);

    actingAs($person);

    $länk = raderingsytaBegär($person);

    $raw = basename($länk);

    $rad = UserDeletion::query()->where('user_id', $person->id)->sole();

    expect($rad->token_hash)->toBe(hash('sha256', $raw))
        ->and($rad->token_hash)->not->toBe($raw);

    // Ingen kolumn i raden bär klartexten. Hashen är det enda som finns.
    $värden = (array) DB::table('user_deletion')->where('id', $rad->id)->first();

    expect($värden)->not->toBeEmpty();

    foreach ($värden as $kolumn => $värde) {
        expect((string) $värde)->not->toContain($raw, "klartext-tokenet står i {$kolumn}");
    }
});

// --- Dokumentationen -----------------------------------------------------

it('har en rad för user_deletion i registerförteckningen', function () {
    $text = File::get(base_path('docs/Registerförteckning.md'));

    expect($text)->toMatch('/^\| Begärd personradering \| `user_deletion` \|/m');
});

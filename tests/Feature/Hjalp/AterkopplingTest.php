<?php

use App\Actions\User\DeleteUser;
use App\Console\PrunesHelpFeedback;
use App\Models\HelpFeedback;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\artisan;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

/*
 * M82 · issue 471 — *Was this helpful?* på artikelsidan,
 * [[ADR-0094 Hjälpcentret]] § 7, [[Konton och åtkomst]] § help_feedback och
 * [[ADR-0017 Missbruksvektorer]] § Beslut.
 *
 * Varje "Klart när"-punkt i issuen motsvaras av ett namngivet test här.
 * Hjälparna har prefixet aterkoppling* för att inte krocka med de globala
 * funktionerna i andra Feature-filer (Pest lägger alla testfiler i samma
 * namnrymd när hela sviten körs).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/** Artikel-id:t som finns i den riktiga katalogen. */
const ATERKOPPLING_ARTIKEL = 'how-do-i-share-a-container';

/** En rad, satt direkt — sessionen blandas inte in. */
function aterkopplingRad(array $attribut = []): HelpFeedback
{
    $rad = new HelpFeedback;
    $rad->article_id = $attribut['article_id'] ?? ATERKOPPLING_ARTIKEL;
    $rad->locale = 'en';
    $rad->helpful = $attribut['helpful'] ?? false;
    $rad->reason = $attribut['reason'] ?? null;
    $rad->comment = $attribut['comment'] ?? null;
    $rad->user_id = $attribut['user_id'] ?? null;
    $rad->save();

    return $rad;
}

/**
 * En inloggad person med en egen Nej-rad att ändra.
 *
 * @return array{0: User, 1: HelpFeedback}
 */
function aterkopplingEgenRad(): array
{
    $person = User::factory()->create();

    actingAs($person);

    $rad = aterkopplingRad(['user_id' => $person->id, 'helpful' => false]);

    return [$person, $rad];
}

/** Källkoden med kommentarer borta — en regel ska inte nöjas av en mening. */
function aterkopplingKod(string $sokvag): string
{
    $kod = File::get(resource_path('js/'.$sokvag));
    $kod = (string) preg_replace('#/\*.*?\*/#s', '', $kod);
    $kod = (string) preg_replace('#<!--.*?-->#s', '', $kod);

    return (string) preg_replace('#^[ \t]*//.*$#m', '', $kod);
}

/*
 * Klart när: en gäst som skickar helpful: false får 201 och en rad utan
 * user_id; ett andra anrop i samma session för samma artikel uppdaterar raden
 * och ger 200 med samma ulid.
 */
it('skapar en gästrad och uppdaterar samma rad i samma session', function () {
    $första = post('/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback', ['helpful' => false]);

    $första->assertStatus(201)->assertJsonStructure(['ulid']);

    $ulid = $första->json('ulid');

    expect(DB::table('help_feedback')->count())->toBe(1);

    $rad = DB::table('help_feedback')->first();

    expect($rad->ulid)->toBe($ulid)
        ->and($rad->user_id)->toBeNull()
        ->and((bool) $rad->helpful)->toBeFalse();

    $andra = post('/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback', ['helpful' => true]);

    $andra->assertOk()->assertJsonPath('ulid', $ulid);

    expect(DB::table('help_feedback')->count())->toBe(1)
        ->and((bool) DB::table('help_feedback')->value('helpful'))->toBeTrue();
});

/*
 * Klart när: en inloggad får en rad med sitt user_id.
 */
it('ger en inloggads rad personens user_id', function () {
    $person = User::factory()->create();

    actingAs($person)
        ->post('/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback', ['helpful' => false])
        ->assertStatus(201);

    expect((int) DB::table('help_feedback')->value('user_id'))->toBe($person->id);
});

/*
 * Klart när: PATCH med reason: outdated sparar orsaken.
 */
it('sparar orsaken vid val', function () {
    [, $rad] = aterkopplingEgenRad();

    patch('/help/feedback/'.$rad->ulid, ['reason' => 'outdated'])
        ->assertOk()
        ->assertJsonPath('ulid', $rad->ulid);

    expect(DB::table('help_feedback')->where('ulid', $rad->ulid)->value('reason'))
        ->toBe('outdated');
});

/*
 * Klart när: med comment från en gäst ger 422 help.comment_requires_login.
 */
it('nekar en gästs kommentar med help.comment_requires_login', function () {
    $skapa = post('/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback', ['helpful' => false]);
    $ulid = $skapa->json('ulid');

    patch('/help/feedback/'.$ulid, ['comment' => 'Det här borde stå'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'help.comment_requires_login')
        ->assertJsonPath('error.data', []);

    expect(DB::table('help_feedback')->where('ulid', $ulid)->value('comment'))->toBeNull();
});

/*
 * Klart när: från en inloggad sparas kommentaren trimmad, och en tom sträng
 * sparas som null.
 */
it('sparar en inloggads kommentar trimmad och en tom som null', function () {
    [, $rad] = aterkopplingEgenRad();

    patch('/help/feedback/'.$rad->ulid, ['comment' => '  Lägg till ett exempel  '])->assertOk();

    expect(DB::table('help_feedback')->where('ulid', $rad->ulid)->value('comment'))
        ->toBe('Lägg till ett exempel');

    patch('/help/feedback/'.$rad->ulid, ['comment' => '   '])->assertOk();

    expect(DB::table('help_feedback')->where('ulid', $rad->ulid)->value('comment'))->toBeNull();
});

/*
 * Klart när: PATCH på en rad där helpful är sant ger 422
 * help.feedback_not_negative.
 */
it('nekar orsak och kommentar på ett Ja', function () {
    $person = User::factory()->create();

    actingAs($person);

    $rad = aterkopplingRad(['user_id' => $person->id, 'helpful' => true]);

    patch('/help/feedback/'.$rad->ulid, ['reason' => 'outdated'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'help.feedback_not_negative')
        ->assertJsonPath('error.data', []);

    expect(DB::table('help_feedback')->where('ulid', $rad->ulid)->value('reason'))->toBeNull();
});

/*
 * Klart när: PATCH på en annans rad, och på en okänd ulid, ger 404.
 */
it('svarar 404 för en annans rad och för en okänd ulid', function () {
    $annen = User::factory()->create();
    $annanRad = aterkopplingRad(['user_id' => $annen->id]);

    actingAs(User::factory()->create());

    patch('/help/feedback/'.$annanRad->ulid, ['reason' => 'outdated'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'resource.not_found')
        ->assertJsonPath('error.data', []);

    patch('/help/feedback/'.Str::ulid(), ['reason' => 'outdated'])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'resource.not_found');
});

/*
 * Klart när: okänd artikel ger 404.
 */
it('svarar 404 för en okänd artikel', function () {
    post('/help/articles/finns-inte/feedback', ['helpful' => true])
        ->assertStatus(404)
        ->assertJsonPath('error.code', 'resource.not_found');

    expect(DB::table('help_feedback')->count())->toBe(0);
});

/*
 * Klart när: reason utanför listan och en kommentar över 1000 tecken ger 422
 * med felkod per fält.
 */
it('svarar 422 med en kod per fält för en okänd orsak och en för lång kommentar', function () {
    [, $rad] = aterkopplingEgenRad();

    patch('/help/feedback/'.$rad->ulid, [
        'reason' => 'bogus',
        'comment' => str_repeat('a', 1001),
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.data.fields.reason.0.code', 'validation.in')
        ->assertJsonPath('error.data.fields.comment.0.code', 'validation.max')
        ->assertJsonPath('error.data.fields.comment.0.data.max', 1000);
});

/*
 * Klart när: det 21:a anropet inom en timme från samma IP som gäst ger 429.
 */
it('stryper en gäst efter tjugo anrop i timmen', function () {
    $url = '/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback';

    for ($i = 1; $i <= 20; $i++) {
        post($url, ['helpful' => false])->assertStatus($i === 1 ? 201 : 200);
    }

    post($url, ['helpful' => false])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'auth.too_many_attempts');
});

/*
 * Klart när: strypningen gäller per användare, inte bara per IP — sextio
 * anrop i timmen för en inloggad. Att det tjugoförsta inte slår till här
 * bevisar att nyckeln är personen och inte adressen.
 */
it('stryper en inloggad först efter sextio anrop i timmen', function () {
    $person = User::factory()->create();

    actingAs($person);

    $url = '/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback';

    for ($i = 1; $i <= 60; $i++) {
        post($url, ['helpful' => false])->assertSuccessful();
    }

    post($url, ['helpful' => false])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'auth.too_many_attempts');
});

/*
 * Klart när: anropen räknas inte dubbelt av ThrottleWrites.
 *
 * ThrottleWrites hoppar över en rutt med egen `throttle:`-middleware, så
 * personens skrivräknare (`writes:<id>`) ska stå orörd efter anropen.
 */
it('räknar inte anropen i ThrottleWrites skrivräknare', function () {
    $person = User::factory()->create();

    actingAs($person);

    for ($i = 1; $i <= 3; $i++) {
        post('/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback', ['helpful' => false])->assertSuccessful();
    }

    expect(RateLimiter::attempts('writes:'.$person->getKey()))->toBe(0);
});

/*
 * Klart när: PrunesHelpFeedback raderar en rad på dag 91 men inte 90.
 */
it('raderar en rad på dag 91 men inte dag 90', function () {
    Carbon::setTestNow('2026-11-24 03:00:00');

    $gammal = aterkopplingRad();
    $gammal->created_at = now()->subDays(91);
    $gammal->updated_at = now()->subDays(91);
    $gammal->save();

    $kvar = aterkopplingRad();
    $kvar->created_at = now()->subDays(90);
    $kvar->updated_at = now()->subDays(90);
    $kvar->save();

    $antal = (new PrunesHelpFeedback)->handle();

    expect($antal)->toBe(1)
        ->and(DB::table('help_feedback')->pluck('ulid')->all())->toBe([$kvar->ulid]);
});

/*
 * Klart när: gallringen är schemalagd dagligen med Schedule::call.
 */
it('är schemalagd dagligen med Schedule::call', function () {
    // artisan(...) bootstrapar konsol-kerneln, som laddar routes/console.php.
    artisan('inspire');

    $handelse = collect(app(Schedule::class)->events())
        ->first(fn ($event) => $event->description === 'prune-help-feedback');

    expect($handelse)->not->toBeNull();
    expect($handelse->getExpression())->toBe('0 0 * * *');

    // Schemalagd som en closure (Schedule::call), inte som ett
    // Artisan-kommando — se AGENTS.md § Subprocesser i driftmiljön.
    expect($handelse->command ?? null)->toBeNull();
});

/*
 * Klart när: DeleteUser raderar användarens rader, och en gästs rader står
 * kvar.
 */
it('raderar personens rader men lämnar gästens kvar', function () {
    $person = User::factory()->create();
    $egen = aterkopplingRad(['user_id' => $person->id]);
    $gast = aterkopplingRad();

    app(DeleteUser::class)->handle($person);

    expect(DB::table('help_feedback')->where('ulid', $egen->ulid)->exists())->toBeFalse()
        ->and(DB::table('help_feedback')->where('ulid', $gast->ulid)->exists())->toBeTrue();
});

/*
 * Fynd 1 i granskningen: en gästs rad som personen loggar in och klickar Nej
 * på igen — flödet ytan själv leder till — ska knytas till personen, och
 * raderas då med hen.
 */
it('knyter en gästrad till personen som loggar in och klickar Nej igen', function () {
    $url = '/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback';

    $ulid = post($url, ['helpful' => false])->assertStatus(201)->json('ulid');

    expect(DB::table('help_feedback')->where('ulid', $ulid)->value('user_id'))->toBeNull();

    $person = User::factory()->create();

    actingAs($person);

    // Sidan har laddats om, så personen klickar Nej igen: samma session,
    // samma rad.
    post($url, ['helpful' => false])->assertOk()->assertJsonPath('ulid', $ulid);

    patch('/help/feedback/'.$ulid, ['comment' => 'Lägg till ett exempel'])->assertOk();

    expect((int) DB::table('help_feedback')->where('ulid', $ulid)->value('user_id'))
        ->toBe($person->id);

    app(DeleteUser::class)->handle($person);

    expect(DB::table('help_feedback')->where('ulid', $ulid)->exists())->toBeFalse();
});

/*
 * Fynd 1, andra halvan: kommentaren knyter raden till personen även när
 * `store()` inte har hunnit köra sedan inloggningen.
 */
it('knyter en gästrad till personen som kommenterar den', function () {
    $ulid = post('/help/articles/'.ATERKOPPLING_ARTIKEL.'/feedback', ['helpful' => false])
        ->assertStatus(201)
        ->json('ulid');

    $person = User::factory()->create();

    actingAs($person);

    patch('/help/feedback/'.$ulid, ['comment' => 'Lägg till ett exempel'])->assertOk();

    expect((int) DB::table('help_feedback')->where('ulid', $ulid)->value('user_id'))
        ->toBe($person->id);

    app(DeleteUser::class)->handle($person);

    expect(DB::table('help_feedback')->where('ulid', $ulid)->exists())->toBeFalse();
});

/*
 * Klart när: källkodsprovet — Ja/Nej och orsak sparas utan knapp,
 * kommentaren bara via Save, och textfältet bara för en inloggad.
 */
it('sparar valen direkt och kommentaren med Save', function () {
    $kod = aterkopplingKod('components/HelpFeedback.vue');

    // Ja och Nej skickas i klicket.
    expect($kod)->toContain('@click="chooseHelpful(true)"')
        ->toContain('@click="chooseHelpful(false)"');

    // Orsaken sparas när den väljs — en radioändring, ingen knapp.
    expect($kod)->toContain('@change="chooseReason(key)"')
        ->toContain('chooseReason');

    // Kommentaren sparas bara genom Save, och fältet bär ingen egen lyssnare.
    expect($kod)->toContain('@click="saveComment"')
        ->toContain('comment: comment.value');

    expect($kod)->not->toMatch('/v-model="comment"[^>]*@(change|input|blur)/');

    // Textfältet ritas bara för en inloggad; gästen får länken i stället.
    expect($kod)->toContain('v-if="loggedIn"')
        ->toContain('usePage().props.auth?.user')
        ->toContain("t('help.feedback.login_to_comment')");
});

/*
 * Klart när: ytan ligger sist på artikelsidan, före Previous/Next.
 */
it('ritas före Previous och Next på artikelsidan', function () {
    $kod = aterkopplingKod('pages/Help/Article.vue');

    $ytan = strpos($kod, '<HelpFeedback');
    $grannarna = strpos($kod, 'v-if="previous || next"');

    expect($ytan)->not->toBeFalse()
        ->and($grannarna)->not->toBeFalse()
        ->and((int) $ytan)->toBeLessThan((int) $grannarna);
});

/*
 * Klart när: de nya nycklarna under help.feedback finns i lang/en/ui.php.
 */
it('har de nya nycklarna under help.feedback i ui.php', function () {
    foreach ([
        'question', 'yes', 'no', 'thanks', 'comment_label', 'save', 'privacy',
        'login_to_comment',
        'reasons.unclear', 'reasons.incomplete', 'reasons.outdated',
        'reasons.incorrect', 'reasons.missing',
        'error.generic', 'error.invalid', 'error.too_many',
        'error.comment_requires_login', 'error.not_negative',
    ] as $nyckel) {
        expect(Lang::get("ui.help.feedback.{$nyckel}", [], 'en'))
            ->not->toBe("ui.help.feedback.{$nyckel}", "ui.help.feedback.{$nyckel} saknas");
    }

    // Orden ur issuen, ordagrant.
    expect(Lang::get('ui.help.feedback.question', [], 'en'))->toBe('Was this helpful?')
        ->and(Lang::get('ui.help.feedback.reasons.unclear', [], 'en'))->toBe('Hard to understand')
        ->and(Lang::get('ui.help.feedback.reasons.incomplete', [], 'en'))->toBe('Something is missing')
        ->and(Lang::get('ui.help.feedback.reasons.outdated', [], 'en'))->toBe("It's out of date")
        ->and(Lang::get('ui.help.feedback.reasons.incorrect', [], 'en'))->toBe("It's wrong")
        ->and(Lang::get('ui.help.feedback.reasons.missing', [], 'en'))
        ->toBe("I couldn't find what I was looking for");
});

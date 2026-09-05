<?php

use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\User;
use App\Support\Notification\EmailChannel;
use App\Support\Notification\NotificationPreferences;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Issue 32b · Avregistreringslänken och List-Unsubscribe, se
 * App\Support\Notification\UnsubscribeLink, App\Http\Controllers\
 * UnsubscribeController och App\Mail\NotificationMail.
 *
 * Avanmälan stänger av en NOTISTYP för en användare via en signerad,
 * tidsbegränsad URL — den skriver ingen undertryckningsrad (Beslut 6). Den
 * tabellen (email_suppression) är 33a och finns inte än; garantin som testas
 * är att bara notification_preference skrivs och att andra typer behåller
 * förvalet.
 *
 * Mejltesterna använder Mail::fake() och renderar det fångade mailet, utom
 * headertestet som skickar på riktigt till array-transporten (phpunit.xml
 * sätter MAIL_MAILER=array) så att List-Unsubscribe-headern faktiskt byggs av
 * ramverket.
 */

/**
 * Ett konto och en medlem med önskade språk — samma form som EpostkanalTest.
 *
 * @return array{0: Account, 1: User}
 */
function avregistreringsKontext(string $kontoLocale = 'sv_SE', ?string $anvandarLocale = 'sv_SE'): array
{
    $account = Account::factory()->create(['locale' => $kontoLocale]);
    $user = User::factory()->create(['locale' => $anvandarLocale]);
    $account->users()->attach($user, ['role' => 'owner']);

    return [$account, $user];
}

/**
 * @param  array<string, mixed>  $payload
 */
function avregistreringsLeverans(Account $account, User $user, string $type, array $payload): NotificationDelivery
{
    $notification = Notification::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => $type,
        'payload' => $payload,
    ]);

    return NotificationDelivery::factory()->create(['notification_id' => $notification->id]);
}

/**
 * @return array<string, string>
 */
function avregistreringsPayload(): array
{
    return [
        'title' => 'Byt impeller',
        'item' => 'Drev',
        'container' => 'Vindil',
        'date' => '2026-09-20',
    ];
}

function avregistreringsUrl(User $user, string $type): string
{
    return URL::temporarySignedRoute(
        'notiser.unsubscribe.confirm',
        now()->addDays(30),
        ['user' => $user->ulid, 'type' => $type],
    );
}

it('notismejlet bär en avregistreringslänk i sidfoten', function () {
    Mail::fake();

    [$account, $user] = avregistreringsKontext('sv_SE', 'sv_SE');
    $delivery = avregistreringsLeverans($account, $user, Notification::TYPE_TASK_DUE, avregistreringsPayload());

    app(EmailChannel::class)->send($delivery);

    $mail = Mail::sent(NotificationMail::class)->first();
    $html = $mail->render();

    expect($html)->toContain('Vill du inte ha den här sortens notiser?');
    expect($html)->toContain('>Avregistrera</a>');
    expect($html)->toContain('/notiser/avregistrera/'.$user->ulid.'/'.Notification::TYPE_TASK_DUE);
});

it('notismejlet bär List-Unsubscribe-headern', function () {
    [$account, $user] = avregistreringsKontext('sv_SE', 'sv_SE');
    $delivery = avregistreringsLeverans($account, $user, Notification::TYPE_TASK_DUE, avregistreringsPayload());

    // Riktig sändning (ingen Mail::fake) mot array-transporten — först då
    // bygger ramverket Symfony-mejlet och applicerar headers()-metoden.
    app(EmailChannel::class)->send($delivery);

    $transport = Mail::getSymfonyTransport();
    expect($transport)->toBeInstanceOf(ArrayTransport::class);

    if (! $transport instanceof ArrayTransport) {
        throw new RuntimeException('Testmiljön ska använda array-transporten (phpunit.xml).');
    }

    $sent = $transport->messages()->first();
    if (! $sent instanceof SentMessage) {
        throw new RuntimeException('Inget mejl nådde array-transporten.');
    }

    /** @var Email $email */
    $email = $sent->getOriginalMessage();

    $listUnsubscribe = $email->getHeaders()->get('List-Unsubscribe');
    $listUnsubscribePost = $email->getHeaders()->get('List-Unsubscribe-Post');

    // RFC 8058: URL:en inom vinkelparenteser, annars ignorerar Gmail den tyst.
    expect($listUnsubscribe)->not->toBeNull();
    expect($listUnsubscribe->getBody())
        ->toStartWith('<'.config('app.url').'/notiser/avregistrera/'.$user->ulid.'/'.Notification::TYPE_TASK_DUE.'?expires=')
        ->toContain('signature=')
        ->toEndWith('>');
    expect($listUnsubscribePost?->getBody())->toBe('List-Unsubscribe=One-Click');
});

it('sidfoten följer mottagarens språk', function () {
    Mail::fake();

    [$svKonto, $svAnvandare] = avregistreringsKontext('sv_SE', 'sv_SE');
    $svensk = avregistreringsLeverans($svKonto, $svAnvandare, Notification::TYPE_TASK_DUE, avregistreringsPayload());
    app(EmailChannel::class)->send($svensk);

    [$enKonto, $enAnvandare] = avregistreringsKontext('en_GB', 'en_GB');
    $engelsk = avregistreringsLeverans($enKonto, $enAnvandare, Notification::TYPE_TASK_DUE, avregistreringsPayload());
    app(EmailChannel::class)->send($engelsk);

    $mejl = Mail::sent(NotificationMail::class);
    expect($mejl)->toHaveCount(2);

    $svHtml = $mejl->get(0)->render();
    $enHtml = $mejl->get(1)->render();

    expect($svHtml)->toContain('Vill du inte ha den här sortens notiser?');
    expect($svHtml)->toContain('>Avregistrera</a>');
    expect($enHtml)->toContain('Do not want this kind of notification?');
    expect($enHtml)->toContain('>Unsubscribe</a>');
});

it('en GET på länken ändrar ingenting', function () {
    $user = User::factory()->create(['locale' => 'sv_SE']);

    get(avregistreringsUrl($user, Notification::TYPE_TASK_DUE))
        ->assertOk()
        ->assertSee('Sluta ta emot '.Notification::TYPE_TASK_DUE.'?');

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('en POST stänger av notistypen', function () {
    $user = User::factory()->create();

    post(avregistreringsUrl($user, Notification::TYPE_TASK_DUE))->assertOk();

    $preferens = NotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('type', Notification::TYPE_TASK_DUE)
        ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
        ->first();

    expect($preferens)->not->toBeNull();
    expect($preferens->enabled)->toBeFalse();
    expect($preferens->digest)->toBeFalse();
});

it('en avanmälan gäller bara den typen', function () {
    $user = User::factory()->create();

    post(avregistreringsUrl($user, Notification::TYPE_TASK_DUE))->assertOk();

    expect(NotificationPreference::query()->count())->toBe(1);

    $preferences = app(NotificationPreferences::class);
    expect($preferences->isEnabled($user, Notification::TYPE_TASK_OVERDUE, NotificationDelivery::CHANNEL_EMAIL))->toBeTrue();
    expect($preferences->isEnabled($user, Notification::TYPE_QUOTA_WARNING, NotificationDelivery::CHANNEL_EMAIL))->toBeTrue();
});

it('en avanmälan skriver ingen undertryckningsrad', function () {
    $user = User::factory()->create();

    post(avregistreringsUrl($user, Notification::TYPE_TASK_DUE))->assertOk();

    // email_suppression (33a) finns inte än. Garantin i Beslut 6 är att bara
    // en notification_preference-rad skrivs och att adressen fortfarande nås
    // för andra typer — en undertryckning skulle träffa allt.
    $rader = NotificationPreference::query()->where('user_id', $user->id)->get();
    expect($rader)->toHaveCount(1);
    expect($rader->first()->type)->toBe(Notification::TYPE_TASK_DUE);

    $preferences = app(NotificationPreferences::class);
    expect($preferences->isEnabled($user, Notification::TYPE_ACCOUNT_INACTIVE, NotificationDelivery::CHANNEL_EMAIL))->toBeTrue();
});

it('en osignerad begäran avvisas', function () {
    $user = User::factory()->create();

    get('/notiser/avregistrera/'.$user->ulid.'/'.Notification::TYPE_TASK_DUE)->assertForbidden();

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('en manipulerad typ i sökvägen avvisas', function () {
    $user = User::factory()->create();
    $url = avregistreringsUrl($user, Notification::TYPE_TASK_DUE);

    $manipulerad = str_replace(Notification::TYPE_TASK_DUE, Notification::TYPE_TASK_OVERDUE, $url);

    get($manipulerad)->assertForbidden();

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('en utgången länk avvisas', function () {
    $user = User::factory()->create();
    $url = URL::temporarySignedRoute(
        'notiser.unsubscribe.confirm',
        now()->subDays(31),
        ['user' => $user->ulid, 'type' => Notification::TYPE_TASK_DUE],
    );

    get($url)->assertForbidden();
});

it('en okänd notistyp ger 404', function () {
    $user = User::factory()->create();

    // Signerad länk — signaturen bevisar att vi skrev den, inte att typen
    // fortfarande finns (Beslut 6).
    get(avregistreringsUrl($user, 'future.type'))->assertNotFound();

    expect(NotificationPreference::query()->count())->toBe(0);
});

it('samma avanmälan två gånger ger en rad', function () {
    $user = User::factory()->create();
    $url = avregistreringsUrl($user, Notification::TYPE_TASK_DUE);

    post($url)->assertOk();
    post($url)->assertOk();

    $rader = NotificationPreference::query()
        ->where('user_id', $user->id)
        ->where('type', Notification::TYPE_TASK_DUE)
        ->where('channel', NotificationDelivery::CHANNEL_EMAIL)
        ->get();

    expect($rader)->toHaveCount(1);
    expect($rader->first()->enabled)->toBeFalse();
});

<?php

use App\Console\DeliversNotifications;
use App\Mail\NotificationMail;
use App\Models\Account;
use App\Models\EmailSuppression;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\User;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 1056 · Mejlleveransen skickar utanför databastransaktionen, se
 * App\Console\DeliversNotifications och migrationen
 * 2026_11_12_010000_add_claimed_at_to_notification_delivery_table.
 *
 * EmailChannel är `final` och kan inte bytas mot en testdubbel; app/Support/
 * Notification/** ligger utanför omfångsrutan. Observationen sker därför i en
 * MessageSending-lyssnare, som körs inne i EmailChannel::send() — samma
 * ögonblick som en testdubbels send() skulle köras. Utan Mail::fake(), som
 * aldrig dispatchar eventet.
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En förfallen e-postleverans på en notis som kanalen kan skicka.
 *
 * @param  array<string, mixed>  $leveransAttribut
 */
function anspraksLeverans(array $leveransAttribut = []): NotificationDelivery
{
    $account = Account::factory()->create(['locale' => 'sv_SE']);
    $user = User::factory()->create(['locale' => 'sv_SE']);
    $account->users()->attach($user, ['role' => 'owner']);

    $notification = Notification::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
        'type' => Notification::TYPE_TASK_DUE,
        'payload' => [
            'title' => 'Byt impeller',
            'item' => 'Drev',
            'container' => 'Vindil',
            'date' => '2026-09-20',
        ],
        'available_at' => now()->subMinute(),
    ]);

    return NotificationDelivery::factory()->create(array_merge([
        'notification_id' => $notification->id,
    ], $leveransAttribut));
}

it('migrationen lägger till claimed_at som nullbar tidsstämpel och down tar bort den', function () {
    expect(Schema::hasColumn('notification_delivery', 'claimed_at'))->toBeTrue();
    $kolumn = collect(Schema::getColumns('notification_delivery'))->firstWhere('name', 'claimed_at');
    expect($kolumn['nullable'])->toBeTrue();

    $migration = require base_path('database/migrations/2026_11_12_010000_add_claimed_at_to_notification_delivery_table.php');
    $migration->down();

    expect(Schema::hasColumn('notification_delivery', 'claimed_at'))->toBeFalse();

    $migration->up();

    expect(Schema::hasColumn('notification_delivery', 'claimed_at'))->toBeTrue();
});

it('utskicket sker utanför transaktion och raden är tagen under utskicket', function () {
    Carbon::setTestNow('2026-11-12 12:00:00');
    $leverans = anspraksLeverans();
    $grundnivå = DB::transactionLevel();
    $nivåUnderUtskick = null;
    $claimedUnderUtskick = null;

    Event::listen(MessageSending::class, function () use (&$nivåUnderUtskick, &$claimedUnderUtskick, $leverans): void {
        $nivåUnderUtskick = DB::transactionLevel();
        $claimedUnderUtskick = DB::table('notification_delivery')
            ->where('id', $leverans->id)
            ->value('claimed_at');
    });

    app(DeliversNotifications::class)->handle();

    expect($nivåUnderUtskick)->toBe($grundnivå);
    expect($claimedUnderUtskick)->not->toBeNull();

    $rad = $leverans->fresh();
    expect($rad->claimed_at)->toBeNull();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($rad->sent_at)->not->toBeNull();
});

it('en rad med claimed_at fem minuter gammal plockas inte', function () {
    Mail::fake();
    Carbon::setTestNow('2026-11-12 12:00:00');
    $claimed = now()->subMinutes(5);
    $leverans = anspraksLeverans(['claimed_at' => $claimed]);

    app(DeliversNotifications::class)->handle();

    Mail::assertNothingSent();
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_PENDING);
    expect($rad->attempts)->toBe(0);
    expect($rad->claimed_at->equalTo($claimed))->toBeTrue();
});

it('en rad med claimed_at elva minuter gammal plockas och skickas', function () {
    Mail::fake();
    Carbon::setTestNow('2026-11-12 12:00:00');
    $leverans = anspraksLeverans(['claimed_at' => now()->subMinutes(11)]);

    app(DeliversNotifications::class)->handle();

    Mail::assertSent(NotificationMail::class, 1);
    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_SENT);
    expect($rad->claimed_at)->toBeNull();
});

it('ett misslyckat utskick nollställer claimed_at och lämnar raden pending', function () {
    Carbon::setTestNow('2026-11-12 12:00:00');
    $leverans = anspraksLeverans();

    Event::listen(MessageSending::class, function (): never {
        throw new RuntimeException('smtp: anslutningen nekades');
    });

    app(DeliversNotifications::class)->handle();

    $rad = $leverans->fresh();
    expect($rad->claimed_at)->toBeNull();
    expect($rad->attempts)->toBe(1);
    expect($rad->status)->toBe(NotificationDelivery::STATUS_PENDING);
});

it('en undertryckt adress ger suppressed utan uppräknad attempts och med claimed_at null', function () {
    Mail::fake();
    Carbon::setTestNow('2026-11-12 12:00:00');
    $leverans = anspraksLeverans();
    EmailSuppression::factory()->create([
        'email' => $leverans->notification->user->email,
        'reason' => EmailSuppression::REASON_HARD_BOUNCE,
    ]);

    app(DeliversNotifications::class)->handle();

    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_SUPPRESSED);
    expect($rad->attempts)->toBe(0);
    expect($rad->claimed_at)->toBeNull();
});

it('en rad som någon annan satt till failed under utskicket skrivs inte över', function () {
    Carbon::setTestNow('2026-11-12 12:00:00');
    $leverans = anspraksLeverans();

    Event::listen(MessageSending::class, function () use ($leverans): void {
        NotificationDelivery::query()
            ->whereKey($leverans->getKey())
            ->update(['status' => NotificationDelivery::STATUS_FAILED, 'last_error' => 'avbruten']);
    });

    app(DeliversNotifications::class)->handle();

    $rad = $leverans->fresh();
    expect($rad->status)->toBe(NotificationDelivery::STATUS_FAILED);
    expect($rad->last_error)->toBe('avbruten');
    expect($rad->attempts)->toBe(0);
    expect($rad->sent_at)->toBeNull();
});

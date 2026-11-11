<?php

use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * Issue 1061 · Datamigrationen som räknar om `usage_counter.storage_bytes`
 * efter den nya regeln — papperskorgen ingår. Se
 * database/migrations/2026_11_12_020000_recount_storage_bytes_with_trash.php
 * och [[ADR-0009 Kvoter och livscykel]] § Uppföljning 2026-11-11.
 *
 * Migrationen körs genom att filen läses in och `up()` anropas direkt: sviten
 * har redan migrerat schemat, och det här provet gäller bara datasteget.
 */

function omrakningKor(): void
{
    $migration = require base_path('database/migrations/2026_11_12_020000_recount_storage_bytes_with_trash.php');
    $migration->up();
}

function omrakningKonto(int $levande, int $slangd): Account
{
    $konto = Account::factory()->create();
    $user = User::factory()->create();
    $container = Container::factory()->for($konto, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $konto->id,
    ]);

    foreach ([[$levande, null], [$slangd, now()]] as [$byteSize, $deletedAt]) {
        Attachment::factory()->for($item, 'item')->create([
            'stored_file_id' => StoredFile::factory()->create(['byte_size' => $byteSize])->id,
            'uploaded_by_user_id' => $user->id,
            'billed_account_id' => $konto->id,
            'deleted_at' => $deletedAt,
        ]);
    }

    return $konto;
}

it('sätter räknaren till 150 för ett konto med en levande fil på 100 och en slängd på 50', function () {
    $konto = omrakningKonto(100, 50);
    UsageCounter::factory()->create([
        'account_id' => $konto->id,
        'storage_bytes' => 100,
        'container_count' => 1,
    ]);

    omrakningKor();

    $rad = DB::table('usage_counter')->where('account_id', $konto->id)->first();
    expect((int) $rad->storage_bytes)->toBe(150);
    expect((int) $rad->container_count)->toBe(1);
});

it('skapar en räknarrad för ett konto som saknade en', function () {
    $konto = omrakningKonto(100, 50);
    expect(DB::table('usage_counter')->where('account_id', $konto->id)->exists())->toBeFalse();

    omrakningKor();

    $rad = DB::table('usage_counter')->where('account_id', $konto->id)->first();
    expect((int) $rad->storage_bytes)->toBe(150);
    expect((int) $rad->container_count)->toBe(0);
    expect($rad->created_at)->not->toBeNull();
    expect($rad->updated_at)->not->toBeNull();
});

it('rör inte ett konto utan bilagor', function () {
    $tomt = Account::factory()->create();
    UsageCounter::factory()->create(['account_id' => $tomt->id, 'storage_bytes' => 0, 'container_count' => 2]);

    omrakningKor();

    expect((int) DB::table('usage_counter')->where('account_id', $tomt->id)->value('container_count'))->toBe(2);
    expect((int) DB::table('usage_counter')->where('account_id', $tomt->id)->value('storage_bytes'))->toBe(0);
});

<?php

use App\Actions\Attachment\StoreAttachment;
use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\Container;
use App\Models\Item;
use App\Models\StoredFile;
use App\Models\UsageCounter;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/*
 * Issue 1050 · Uppladdningen lämnar varken en rad utan bytes eller bytes utan
 * rad. Se App\Actions\Attachment\StoreAttachment::store() och
 * [[Filer och lagring]] § Radering. Anropen går direkt mot handle() så att
 * kontrollerns tidiga kvotkontroll kringgås och den auktoritativa, inne i
 * transaktionen, är den som fäller.
 */

beforeEach(function () {
    Storage::fake('files');
});

/**
 * @return array{0: Account, 1: User, 2: Item}
 */
function uppladdningsluckorSetup(): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $account->users()->attach($user, ['role' => 'owner']);
    $container = Container::factory()->for($account, 'account')->create();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    return [$account, $user, $item];
}

/**
 * Fyller kontots räknare över planens tak så att den auktoritativa
 * kontrollen nekar.
 */
function uppladdningsluckorFyllKvoten(Account $account): void
{
    UsageCounter::query()->updateOrCreate(
        ['account_id' => $account->id],
        ['storage_bytes' => PHP_INT_MAX >> 2, 'container_count' => 0],
    );
}

function uppladdningsluckorFil(string $innehåll): UploadedFile
{
    return UploadedFile::fake()->createWithContent('manual.pdf', $innehåll);
}

function uppladdningsluckorSökväg(string $innehåll): string
{
    $hash = hash('sha256', $innehåll);

    return substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
}

it('en uppladdning vars auktoritativa kvotkontroll faller lämnar varken fil eller rad', function () {
    [$account, $user, $item] = uppladdningsluckorSetup();
    uppladdningsluckorFyllKvoten($account);

    $innehåll = 'bytes som aldrig får ligga kvar';

    expect(fn () => app(StoreAttachment::class)->handle($item, uppladdningsluckorFil($innehåll), $user, $account))
        ->toThrow(ApiException::class);

    expect(Storage::disk('files')->exists(uppladdningsluckorSökväg($innehåll)))->toBeFalse();
    expect(StoredFile::count())->toBe(0);
    expect(Attachment::count())->toBe(0);
});

it('samma fel när en annan levande bilaga delar innehållet lämnar filen och räknaren orörda', function () {
    [$account, $user, $item] = uppladdningsluckorSetup();
    $innehåll = 'delat innehåll';

    $första = app(StoreAttachment::class)->handle($item, uppladdningsluckorFil($innehåll), $user, $account);
    expect($första->storedFile->reference_count)->toBe(1);

    uppladdningsluckorFyllKvoten($account);

    expect(fn () => app(StoreAttachment::class)->handle($item, uppladdningsluckorFil($innehåll), $user, $account))
        ->toThrow(ApiException::class);

    expect(Storage::disk('files')->exists(uppladdningsluckorSökväg($innehåll)))->toBeTrue();
    expect(StoredFile::sole()->reference_count)->toBe(1);
    expect(Attachment::count())->toBe(1);
});

it('saknas filen trots att raden finns skrivs den om och bilagan går att läsa', function () {
    [$account, $user, $item] = uppladdningsluckorSetup();
    $innehåll = 'filen försvann under oss';
    $sökväg = uppladdningsluckorSökväg($innehåll);

    app(StoreAttachment::class)->handle($item, uppladdningsluckorFil($innehåll), $user, $account);
    Storage::disk('files')->delete($sökväg);
    expect(Storage::disk('files')->exists($sökväg))->toBeFalse();

    $ny = app(StoreAttachment::class)->handle($item, uppladdningsluckorFil($innehåll), $user, $account);

    expect(Storage::disk('files')->exists($sökväg))->toBeTrue();
    expect(Storage::disk('files')->get($ny->storedFile->storage_path))->toBe($innehåll);
    expect(Attachment::query()->whereKey($ny->id)->exists())->toBeTrue();
});

it('en rad med reference_count noll och purge_after får räknare 1, purge_after null och filen kvar', function () {
    [$account, $user, $item] = uppladdningsluckorSetup();
    $innehåll = 'väntar på fysisk radering';
    $sökväg = uppladdningsluckorSökväg($innehåll);

    StoredFile::factory()->create([
        'content_hash' => hash('sha256', $innehåll),
        'byte_size' => strlen($innehåll),
        'storage_path' => $sökväg,
        'reference_count' => 0,
        'purge_after' => now()->addDays(10),
    ]);
    Storage::disk('files')->put($sökväg, $innehåll);

    app(StoreAttachment::class)->handle($item, uppladdningsluckorFil($innehåll), $user, $account);

    $rad = StoredFile::sole();
    expect($rad->reference_count)->toBe(1);
    expect($rad->purge_after)->toBeNull();
    expect(Storage::disk('files')->get($sökväg))->toBe($innehåll);
});

it('ett fel vid städningen döljer inte det ursprungliga och loggas som varning', function () {
    [$account, $user, $item] = uppladdningsluckorSetup();
    uppladdningsluckorFyllKvoten($account);

    $spion = Mockery::mock(Storage::disk('files'))->makePartial();
    $spion->shouldReceive('delete')->andThrow(new RuntimeException('disken vägrar'));
    Storage::set('files', $spion);

    Log::shouldReceive('warning')->once();

    $fångad = null;
    try {
        app(StoreAttachment::class)->handle($item, uppladdningsluckorFil('städningen faller'), $user, $account);
    } catch (ApiException $e) {
        $fångad = $e;
    }

    expect($fångad)->not->toBeNull();
    expect($fångad?->errorCode())->toBe('quota.storage_exceeded');
});

it('det ursprungliga undantaget når anroparen oförändrat när städningen lyckas', function () {
    [$account, $user, $item] = uppladdningsluckorSetup();
    uppladdningsluckorFyllKvoten($account);

    $fångad = null;
    try {
        app(StoreAttachment::class)->handle($item, uppladdningsluckorFil('vanligt fel'), $user, $account);
    } catch (ApiException $e) {
        $fångad = $e;
    }

    expect($fångad)->not->toBeNull();
    expect($fångad?->errorCode())->toBe('quota.storage_exceeded');
});

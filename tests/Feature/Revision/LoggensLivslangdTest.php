<?php

use App\Actions\Account\DeleteAccount;
use App\Actions\Attachment\PurgeAttachment;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Trash\PurgeContainer;
use App\Actions\Trash\PurgeContent;
use App\Console\DeletesDormantAccounts;
use App\Console\PurgesExpiredTrash;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\Item;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/*
 * Issue 107 · Händelseloggen överlever det den handlar om. Se
 * [[ADR-0043 Tre loggar]] § Händelseloggen, [[Konton och åtkomst]] § audit_log
 * och migreringen 2026_09_23_000000_remove_audit_log_foreign_keys_and_add_item_id.
 *
 * Före issue 107 bar `audit_log.account_id`, `user_id` och `container_id`
 * ON DELETE RESTRICT. `PurgeContainer` tar hårt bort en container efter
 * trettio dagar i papperskorgen men rensar inte loggen — och ska inte göra
 * det — så en enda loggrad fällde den nattliga gallringen varje natt. Samma
 * sak gällde `DeleteAccount` för ett vilande konto. Testerna här bevisar att
 * raden överlever sitt subjekt, att de två sista raderna skrivs, och att
 * `item_id` finns och sätts.
 *
 * Hjälparna har prefixet livslangd* för att inte krocka med de globala
 * hjälparna i andra Feature-filer (gallring* i Trash, kontoradering* i Konto,
 * loggRad() i RevisionsloggTest).
 */

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * En loggrad i containern, skriven av fabriken — anropsställena bevisas i
 * RevisionsloggTest, här är raden bara ett subjekt som ska överleva.
 */
function livslangdLogg(Container $container, Account $account, ?User $användare = null): AuditLog
{
    return AuditLog::factory()->create([
        'container_id' => $container->id,
        'account_id' => $account->id,
        'user_id' => $användare?->id,
    ]);
}

/**
 * Ett konto, en användare och en container — samma utgångsläge som
 * gallringContainer() i Trash.
 *
 * @return array{0: Account, 1: User, 2: Container}
 */
function livslangdContainer(): array
{
    $account = Account::factory()->create();
    $user = User::factory()->create();
    $container = Container::factory()->for($account, 'account')->create();

    return [$account, $user, $container];
}

/**
 * Ett "vilande" konto: skapat 2024-06-01 med en medlem och direkt stängt för
 * inaktivitet. Kör jobbet med Carbon::setTestNow('2026-09-04 ...') — då har
 * kontot legat orört i 27 månader och passerat alla tre stegen.
 *
 * @return array{0: Account, 1: User}
 */
function livslangdVilande(): array
{
    Carbon::setTestNow('2024-06-01 12:00:00');

    $konto = Account::factory()->create();
    $medlem = User::factory()->create();
    $konto->users()->attach($medlem, ['role' => 'owner']);
    $konto->update(['status' => 'closed', 'read_only_reason' => 'inactivity']);

    return [$konto, $medlem];
}

/**
 * Kör kontoraderingsjobbet precis som schemaläggningen gör.
 */
function livslangdKontoradering(): void
{
    $purgeContent = new PurgeContent(new PurgeAttachment);

    (new DeletesDormantAccounts(new DeleteAccount(new PurgeContainer($purgeContent))))->handle();
}

/**
 * Kör papperskorgens gallring precis som schemaläggningen gör.
 *
 * @return array{attachment: int, item: int, category: int, tag: int, container: int}
 */
function livslangdGallring(): array
{
    $purgeContent = new PurgeContent(new PurgeAttachment);

    return (new PurgesExpiredTrash($purgeContent, new PurgeContainer($purgeContent)))->handle();
}

it('en container med en loggrad gallras', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = livslangdContainer();

    $rad = livslangdLogg($container, $account, $user);

    // Containern ligger i papperskorgen sedan mer än trettio dagar —
    // retentionen har passerats och gallringen ska ta den.
    $container->deleted_at = Carbon::parse('2026-08-01 12:00:00');
    $container->save();

    $antal = livslangdGallring();

    // Loggraden får inte fälla gallringen: med ON DELETE RESTRICT föll
    // forceDelete på ett främmandenyckelfel och containern blev kvar natt
    // efter natt.
    expect($antal['container'])->toBe(1);
    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse();

    // Raden finns kvar efteråt, med sitt container_id orört — den är
    // historik och gallras först tolv månader efter container.purged
    // (issue 115).
    expect(DB::table('audit_log')->where('id', $rad->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('id', $rad->id)->value('container_id'))->toBe($container->id);
});

it('ett vilande konto med en loggrad raderas', function () {
    [$konto, $medlem] = livslangdVilande();

    $rad = AuditLog::factory()->create([
        'account_id' => $konto->id,
        'user_id' => $medlem->id,
    ]);

    Carbon::setTestNow('2026-09-04 12:00:00');

    expect(fn () => livslangdKontoradering())->not->toThrow(Throwable::class);

    expect(Account::query()->whereKey($konto->id)->exists())->toBeFalse();

    // Samma sak som för containern: raden blockerar inte raderingen och
    // lever vidare med sitt account_id orört.
    expect(DB::table('audit_log')->where('id', $rad->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('id', $rad->id)->value('account_id'))->toBe($konto->id);
});

it('gallringen skriver container.purged', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, , $container] = livslangdContainer();

    $container->deleted_at = Carbon::parse('2026-08-01 12:00:00');
    $container->save();

    livslangdGallring();

    $rader = DB::table('audit_log')->where('action', AuditLog::ACTION_CONTAINER_PURGED)->get();

    // Exakt en rad: den är ankaret issue 115 räknar tolv månader från, och
    // gallringen skriver ingenting utöver den (issue 109).
    expect($rader)->toHaveCount(1);

    $rad = $rader->first();
    expect($rad->container_id)->toBe($container->id);
    expect($rad->account_id)->toBe($account->id);

    // Ingen handlande användare: det är jobbet som gallrar (issue 40 §
    // Beslut 11).
    expect($rad->user_id)->toBeNull();
});

it('kontoraderingen skriver account.deleted', function () {
    [$konto] = livslangdVilande();

    Carbon::setTestNow('2026-09-04 12:00:00');
    livslangdKontoradering();

    $rader = DB::table('audit_log')->where('action', AuditLog::ACTION_ACCOUNT_DELETED)->get();

    expect($rader)->toHaveCount(1);

    $rad = $rader->first();
    expect($rad->account_id)->toBe($konto->id);
    expect($rad->container_id)->toBeNull();
    expect($rad->user_id)->toBeNull();
});

it('en gallring som rullas tillbaka skriver ingen container.purged', function () {
    Carbon::setTestNow('2026-09-02 12:00:00');
    [$account, $user, $container] = livslangdContainer();

    $item = Item::factory()->for($container, 'container')->create([
        'name' => 'Trasig',
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    // Raden skrivs i gallringens EGEN transaktion: faller gallringen finns
    // ingen rad som påstår att containern försvann. Samma krav som issue 40
    // § Beslut 9 ställer på ägarbytet.
    $purgeContent = new class(new PurgeAttachment) extends PurgeContent
    {
        public function item(Item $item): void
        {
            if ($item->name === 'Trasig') {
                throw new RuntimeException('trasig rad');
            }

            parent::item($item);
        }
    };

    expect(fn () => (new PurgeContainer($purgeContent))->handle($container))
        ->toThrow(RuntimeException::class);

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeTrue();
    expect(Item::withTrashed()->whereKey($item->id)->exists())->toBeTrue();
    expect(DB::table('audit_log')->where('action', AuditLog::ACTION_CONTAINER_PURGED)->count())->toBe(0);
});

it('RecordAuditEvent sätter item_id när ett item skickas in', function () {
    [$account, $user, $container] = livslangdContainer();
    $item = Item::factory()->for($container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);

    $med = (new RecordAuditEvent)->handle(
        action: AuditLog::ACTION_CONTAINER_TRANSFERRED,
        account: $account,
        user: $user,
        container: $container,
        item: $item,
    );

    expect($med->item_id)->toBe($item->id);
    expect(DB::table('audit_log')->where('id', $med->id)->value('item_id'))->toBe($item->id);

    // Utan item är kolumnen null — en kontonivåhändelse hör inte till något
    // item, och raden ska inte hitta på ett.
    $utan = (new RecordAuditEvent)->handle(
        action: AuditLog::ACTION_CONTAINER_TRANSFERRED,
        account: $account,
    );

    expect($utan->item_id)->toBeNull();
});

it('audit_log har kolumnen item_id, de två nya indexen och inga främmande nycklar', function () {
    expect(Schema::hasColumn('audit_log', 'item_id'))->toBeTrue();

    $indexer = collect(Schema::getIndexes('audit_log'))
        ->mapWithKeys(fn (array $index) => [implode(',', $index['columns']) => $index['name']]);

    expect($indexer)->toHaveKey('item_id,created_at');
    expect($indexer)->toHaveKey('user_id,created_at');

    // Containerns läsväg från issue 40 ligger kvar orörd.
    expect($indexer)->toHaveKey('container_id,created_at');

    // Poängen med hela issuen: de tre kolumnerna är identifierare nu, inte
    // främmande nycklar. En loggrad kan inte längre fälla en gallring.
    expect(Schema::getForeignKeys('audit_log'))->toBe([]);
});

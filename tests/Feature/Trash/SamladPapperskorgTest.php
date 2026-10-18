<?php

use App\Actions\Access\ResolveItemScope;
use App\Actions\Audit\RecordAuditEvent;
use App\Actions\Inbox\ResolveInbox;
use App\Actions\Item\DeleteItem;
use App\Actions\Trash\ListAllTrash;
use App\Models\Account;
use App\Models\Attachment;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Container;
use App\Models\ContainerAccess;
use App\Models\Item;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * M39 · issue 318 — den samlade papperskorgens urval. Se
 * [[ADR-0065 Den samlade papperskorgen]] § 2–5 och 7 och
 * App\Actions\Trash\ListAllTrash.
 *
 * Actionen har ingen rutt än — sidan och grinden kommer i issue 319 — så
 * testerna anropar `handle()` direkt. Hjälparna är namnrymda
 * (`samladPapperskorg*`) för att inte krocka med de andra testfilerna: Pest
 * delar global namnrymd mellan filerna. kontoMedMedlem(),
 * papperskorgsItem() och papperskorgsBilaga() är globala i
 * tests/Support/Testhjalpare.php.
 */

/**
 * Ett konto med en medlem, utan Sanctum-headers — Actionen tar en User.
 *
 * @return array{0: Account, 1: User} [$account, $user]
 */
function samladPapperskorgKonto(): array
{
    [$account, $user] = kontoMedMedlem();

    return [$account, $user];
}

/**
 * En levande container i kontot.
 */
function samladPapperskorgContainer(Account $account): Container
{
    return Container::factory()->for($account, 'account')->create();
}

/**
 * Ett item med kontrollerad raderingstidpunkt. `null` lämnar det levande.
 */
function samladPapperskorgItem(Container $container, Account $account, User $user, string $namn, ?Carbon $deletedAt = null): Item
{
    $item = papperskorgsItem($container, $account, $user, ['name' => $namn]);

    if ($deletedAt !== null) {
        $item->deleted_at = $deletedAt;
        $item->save();
    }

    return $item;
}

/**
 * En bilaga med kontrollerad raderingstidpunkt.
 */
function samladPapperskorgBilaga(Item $item, Account $account, User $user, string $filnamn, ?Carbon $deletedAt = null): Attachment
{
    $bilaga = papperskorgsBilaga($item, $account, $user, ['filename' => $filnamn]);

    if ($deletedAt !== null) {
        $bilaga->deleted_at = $deletedAt;
        $bilaga->save();
    }

    return $bilaga;
}

/**
 * Mjukraderar en container med kontrollerad tidpunkt.
 */
function samladPapperskorgRaderaContainer(Container $container, Carbon $deletedAt): void
{
    $container->deleted_at = $deletedAt;
    $container->save();
}

/**
 * En itemgrant, som i tests/Feature/Omfang/PapperskorgsfilterTest.php.
 */
function samladPapperskorgGrant(Container $container, User $user, Item $item, string $nivå = 'read'): ContainerAccess
{
    return ContainerAccess::factory()->create([
        'container_id' => $container->id,
        'item_id' => $item->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $nivå,
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);
}

/**
 * ULID:erna i svarets poster.
 *
 * @param  array{entries: list<array<string, mixed>>}  $svar
 * @return list<string>
 */
function samladPapperskorgUlider(array $svar): array
{
    return array_column($svar['entries'], 'ulid');
}

/**
 * Posterna nycklade på ULID.
 *
 * @param  array{entries: list<array<string, mixed>>}  $svar
 * @return array<string, array<string, mixed>>
 */
function samladPapperskorgPoster(array $svar): array
{
    return array_column($svar['entries'], null, 'ulid');
}

it('listar raderade items, bilagor och containrar med det senast raderade först', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $tidig = now()->subHours(3);
    $mellan = now()->subHours(2);
    $sen = now()->subHour();

    $item = samladPapperskorgItem($container, $account, $user, 'Motorn', $tidig);
    $levande = samladPapperskorgItem($container, $account, $user, 'Växellådan');
    $bilaga = samladPapperskorgBilaga($levande, $account, $user, 'faktura.pdf', $sen);

    $raderadContainer = samladPapperskorgContainer($account);
    samladPapperskorgRaderaContainer($raderadContainer, $mellan);

    $svar = app(ListAllTrash::class)->handle($user, null, null, null);

    expect(samladPapperskorgUlider($svar))->toBe([
        $bilaga->ulid,
        $raderadContainer->ulid,
        $item->ulid,
    ]);
});

it('varje post bär ikon, container och item där de finns', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $item = samladPapperskorgItem($container, $account, $user, 'Motorn', now()->subHours(3));
    $item->icon = 'engine';
    $item->icon_color = 'blue';
    $item->save();

    $levande = samladPapperskorgItem($container, $account, $user, 'Växellådan');
    $bilaga = samladPapperskorgBilaga($levande, $account, $user, 'faktura.pdf', now()->subHours(2));

    $raderadContainer = samladPapperskorgContainer($account);
    $raderadContainer->icon = 'anchor';
    $raderadContainer->icon_color = 'green';
    $raderadContainer->save();
    samladPapperskorgRaderaContainer($raderadContainer, now()->subHour());

    $svar = app(ListAllTrash::class)->handle($user, null, null, null);
    $poster = samladPapperskorgPoster($svar);

    foreach ($poster as $post) {
        expect(array_keys($post))->toBe([
            'type',
            'ulid',
            'label',
            'icon',
            'icon_color',
            'container',
            'item',
            'deleted_at',
            'expires_at',
            'deleted_by',
        ]);
    }

    expect($poster[$item->ulid])->toMatchArray([
        'type' => 'item',
        'label' => 'Motorn',
        'icon' => 'engine',
        'icon_color' => 'blue',
        'container' => ['ulid' => $container->ulid, 'name' => $container->name],
        'item' => null,
    ]);

    expect($poster[$bilaga->ulid])->toMatchArray([
        'type' => 'attachment',
        'label' => 'faktura.pdf',
        'icon' => null,
        'icon_color' => null,
        'container' => ['ulid' => $container->ulid, 'name' => $container->name],
        'item' => ['ulid' => $levande->ulid, 'name' => 'Växellådan'],
    ]);

    expect($poster[$raderadContainer->ulid])->toMatchArray([
        'type' => 'container',
        'label' => $raderadContainer->name,
        'icon' => 'anchor',
        'icon_color' => 'green',
        'container' => null,
        'item' => null,
    ]);

    // Subjects bär raderna grinden i issue 319 prövar, med relationerna
    // satta i minnet så att ingen rad kostar ett uppslag.
    expect($svar['subjects'][$item->ulid]->relationLoaded('container'))->toBeTrue();
    expect($svar['subjects'][$bilaga->ulid]->relationLoaded('item'))->toBeTrue();
    expect($svar['subjects'][$raderadContainer->ulid]->relationLoaded('account'))->toBeTrue();
});

it('den med begränsat omfång ser bara de items den når och deras bilagor', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $motor = samladPapperskorgItem($container, $account, $user, 'Motorn', now()->subHours(2));
    $mast = samladPapperskorgItem($container, $account, $user, 'Masten', now()->subHours(2));

    $motornsBilaga = samladPapperskorgBilaga($motor, $account, $user, 'motorns.pdf', now()->subHour());
    $mastensBilaga = samladPapperskorgBilaga($mast, $account, $user, 'mastens.pdf', now()->subHour());

    $mottagare = User::factory()->create();
    samladPapperskorgGrant($container, $mottagare, $motor);

    $svar = app(ListAllTrash::class)->handle($mottagare, null, null, null);

    $ulider = samladPapperskorgUlider($svar);

    expect($ulider)->toContain($motor->ulid);
    expect($ulider)->toContain($motornsBilaga->ulid);
    expect($ulider)->not->toContain($mast->ulid);
    expect($ulider)->not->toContain($mastensBilaga->ulid);

    // Ingen containerrad: hon är inte medlem i ägarkontot.
    expect(collect($svar['entries'])->pluck('type')->unique()->values()->all())
        ->not->toContain('container');
});

it('en användare ser inga rader ur en container den inte når', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    samladPapperskorgItem($container, $account, $user, 'Motorn', now()->subHour());
    samladPapperskorgBilaga(
        samladPapperskorgItem($container, $account, $user, 'Växellådan'),
        $account,
        $user,
        'faktura.pdf',
        now()->subHour(),
    );

    [$annatKonto, $annanUser] = samladPapperskorgKonto();
    $annanContainer = samladPapperskorgContainer($annatKonto);
    samladPapperskorgItem($annanContainer, $annatKonto, $annanUser, 'Deras', now()->subHour());

    $svar = app(ListAllTrash::class)->handle($annanUser, null, null, null);

    expect(samladPapperskorgUlider($svar))->not->toContain($container->ulid);
    expect(collect($svar['entries'])->pluck('label'))->not->toContain('Motorn');
    expect(collect($svar['entries'])->pluck('label'))->not->toContain('faktura.pdf');
});

it('containrar syns bara för ägarkontots medlemmar, och en delegerad åtkomst räcker inte', function () {
    [$account, $user] = samladPapperskorgKonto();
    $raderad = samladPapperskorgContainer($account);
    samladPapperskorgRaderaContainer($raderad, now()->subHour());

    $medlem = app(ListAllTrash::class)->handle($user, 'container', null, null);
    expect(samladPapperskorgUlider($medlem))->toBe([$raderad->ulid]);

    $delegerad = User::factory()->create();
    ContainerAccess::factory()->create([
        'container_id' => $raderad->id,
        'item_id' => null,
        'grantee_type' => 'user',
        'grantee_id' => $delegerad->id,
        'level' => 'delete',
        'kind' => 'guest',
        'granted_by_user_id' => User::factory()->create()->id,
    ]);

    $svar = app(ListAllTrash::class)->handle($delegerad, 'container', null, null);
    expect($svar['entries'])->toBe([]);
});

it('inget ur en raderad container listas för sig', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $item = samladPapperskorgItem($container, $account, $user, 'Motorn', now()->subHours(2));
    samladPapperskorgBilaga($item, $account, $user, 'faktura.pdf', now()->subHours(2));

    samladPapperskorgRaderaContainer($container, now()->subHour());

    $svar = app(ListAllTrash::class)->handle($user, null, null, null);

    expect(samladPapperskorgUlider($svar))->toBe([$container->ulid]);
    expect($svar['entries'][0]['type'])->toBe('container');
});

it('utgånget innehåll, kategorier, taggar och containerns egen bild listas inte', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    try {
        [$account, $user] = samladPapperskorgKonto();
        $container = samladPapperskorgContainer($account);
        $retention = (int) config('files.trash_retention_days');

        $kvar = samladPapperskorgItem($container, $account, $user, 'Kvar', now()->subDay());

        samladPapperskorgItem($container, $account, $user, 'Utgånget', now()->subDays($retention + 1));

        $levande = samladPapperskorgItem($container, $account, $user, 'Levande');
        samladPapperskorgBilaga($levande, $account, $user, 'utgangen.pdf', now()->subDays($retention + 1));

        $utgangenContainer = samladPapperskorgContainer($account);
        samladPapperskorgRaderaContainer($utgangenContainer, now()->subDays($retention + 1));

        foreach ([
            Category::factory()->for($container, 'container')->create(['name' => 'Rigg']),
            Tag::factory()->for($container, 'container')->create(['name' => 'Vinter']),
        ] as $organisation) {
            $organisation->deleted_at = now();
            $organisation->save();
        }

        // Containerns egen bild: item_id = NULL, alltså ingen bilaga här.
        $bild = Attachment::factory()->create([
            'container_id' => $container->id,
            'item_id' => null,
            'filename' => 'kontaktbild.jpg',
        ]);
        $bild->deleted_at = now();
        $bild->save();

        $svar = app(ListAllTrash::class)->handle($user, null, null, null);

        expect(samladPapperskorgUlider($svar))->toBe([$kvar->ulid]);
    } finally {
        Carbon::setTestNow();
    }
});

it('inboxens raderade items och bilagor finns med', function () {
    [$account, $user] = samladPapperskorgKonto();

    $inbox = app(ResolveInbox::class)->handle($user);

    $inboxBilaga = samladPapperskorgBilaga($inbox, $account, $user, 'inkast.pdf', now()->subHours(2));

    // Ett item i inboxens container, mjukraderat. Inboxcontainern nås bara
    // genom ResolveInbox::existing() — den filtreras bort av listable().
    $inboxItem = Item::factory()->for($inbox->container, 'container')->create([
        'created_by_user_id' => $user->id,
        'created_by_account_id' => $account->id,
    ]);
    $inboxItem->deleted_at = now()->subHour();
    $inboxItem->save();

    $svar = app(ListAllTrash::class)->handle($user, null, null, null);
    $ulider = samladPapperskorgUlider($svar);

    expect($ulider)->toContain($inboxBilaga->ulid);
    expect($ulider)->toContain($inboxItem->ulid);
});

it('type begränsar urvalet', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $item = samladPapperskorgItem($container, $account, $user, 'Motorn', now()->subHours(3));
    $levande = samladPapperskorgItem($container, $account, $user, 'Växellådan');
    $bilaga = samladPapperskorgBilaga($levande, $account, $user, 'faktura.pdf', now()->subHours(2));

    $raderadContainer = samladPapperskorgContainer($account);
    samladPapperskorgRaderaContainer($raderadContainer, now()->subHour());

    $action = app(ListAllTrash::class);

    expect(samladPapperskorgUlider($action->handle($user, 'item', null, null)))->toBe([$item->ulid]);
    expect(samladPapperskorgUlider($action->handle($user, 'attachment', null, null)))->toBe([$bilaga->ulid]);
    expect(samladPapperskorgUlider($action->handle($user, 'container', null, null)))->toBe([$raderadContainer->ulid]);
    expect(samladPapperskorgUlider($action->handle($user, null, null, null)))->toHaveCount(3);
});

it('q begränsar urvalet mot itemets namn, bilagans filnamn och containerns namn', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $item = samladPapperskorgItem($container, $account, $user, 'Startmotorn', now()->subHours(3));
    $levande = samladPapperskorgItem($container, $account, $user, 'Växellådan');
    $bilaga = samladPapperskorgBilaga($levande, $account, $user, 'faktura-startmotor.pdf', now()->subHours(2));

    $raderadContainer = samladPapperskorgContainer($account);
    $raderadContainer->name = 'Startmotorns låda';
    $raderadContainer->save();
    samladPapperskorgRaderaContainer($raderadContainer, now()->subHour());

    $action = app(ListAllTrash::class);

    expect(collect(samladPapperskorgUlider($action->handle($user, null, 'startmotor', null)))->sort()->values()->all())
        ->toBe(collect([$item->ulid, $bilaga->ulid, $raderadContainer->ulid])->sort()->values()->all());

    expect(samladPapperskorgUlider($action->handle($user, null, '  faktura  ', null)))->toBe([$bilaga->ulid]);
    expect(samladPapperskorgUlider($action->handle($user, null, 'låda', null)))->toBe([$raderadContainer->ulid]);

    // En tom sträng är null: hela listan.
    expect($action->handle($user, null, '', null)['entries'])->toHaveCount(3);
    expect($action->handle($user, null, '   ', null)['entries'])->toHaveCount(3);
});

it('markören ger nästa 50 utan dubbletter och utan luckor, även när två rader raderades samtidigt', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    try {
        [$account, $user] = samladPapperskorgKonto();
        $container = samladPapperskorgContainer($account);

        $tidpunkt = now()->subHour();
        $skapade = [];

        foreach (range(1, 60) as $i) {
            // Samma sekund för alla: ordningen är då ULID:ns, och det är
            // just den som måste bära markören över sidgränsen.
            $skapade[] = samladPapperskorgItem($container, $account, $user, "Raderad $i", $tidpunkt);
        }

        $action = app(ListAllTrash::class);

        $första = $action->handle($user, null, null, null);

        expect($första['entries'])->toHaveCount(ListAllTrash::PER_PAGE);
        expect($första['next_cursor'])->toBeString();

        $andra = $action->handle($user, null, null, $första['next_cursor']);

        expect($andra['entries'])->toHaveCount(10);
        expect($andra['next_cursor'])->toBeNull();

        $allUlider = collect($skapade)->pluck('ulid')->sort()->values()->all();
        $sidaEtt = samladPapperskorgUlider($första);
        $sidaTva = samladPapperskorgUlider($andra);

        // Inga dubbletter och ingen lucka.
        expect(array_intersect($sidaEtt, $sidaTva))->toBe([]);
        expect(collect([...$sidaEtt, ...$sidaTva])->sort()->values()->all())->toBe($allUlider);

        // Sorteringen är total: ULID stigande inom samma sekund.
        expect($sidaEtt)->toBe(collect($sidaEtt)->sort()->values()->all());
    } finally {
        Carbon::setTestNow();
    }
});

it('deleted_by är namnet från den senaste raderingen', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    // Levande från början: den senaste händelsen skrivs av DeleteItem, den
    // riktiga raderingsvägen, och den bär inget subject — bara item_id.
    $item = samladPapperskorgItem($container, $account, $user, 'Motorn');
    $första = User::factory()->create(['name' => 'Första Handlingen']);
    $senaste = User::factory()->create(['name' => 'Senaste Handlingen']);

    // Relativt nu, inte ett fast datum: raden ska ligga inom retentionen
    // även när sviten körs långt senare.
    $bas = now();

    Carbon::setTestNow($bas->copy()->subMinutes(2));

    try {
        app(RecordAuditEvent::class)->handle(
            action: AuditLog::ACTION_ITEM_DELETED,
            user: $första,
            container: $container,
            item: $item,
        );

        Carbon::setTestNow($bas->copy()->subMinute());

        app(DeleteItem::class)->handle($item, $senaste);
    } finally {
        Carbon::setTestNow();
    }

    $svar = app(ListAllTrash::class)->handle($user, 'item', null, null);

    expect($svar['entries'][0]['deleted_by'])->toBe('Senaste Handlingen');
});

it('deleted_by är null när händelsen saknas eller ett jobb raderade raden', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $utanHandelse = samladPapperskorgItem($container, $account, $user, 'Utan händelse', now()->subHours(2));
    $jobbRaderad = samladPapperskorgItem($container, $account, $user, 'Jobbets', now()->subHour());

    app(RecordAuditEvent::class)->handle(
        action: AuditLog::ACTION_ITEM_DELETED,
        container: $container,
        item: $jobbRaderad,
    );

    $svar = app(ListAllTrash::class)->handle($user, 'item', null, null);
    $poster = samladPapperskorgPoster($svar);

    expect($poster[$utanHandelse->ulid]['deleted_by'])->toBeNull();
    expect($poster[$jobbRaderad->ulid]['deleted_by'])->toBeNull();
});

it('deleted_by läses ur subject för bilagor och containrar', function () {
    [$account, $user] = samladPapperskorgKonto();
    $container = samladPapperskorgContainer($account);

    $levande = samladPapperskorgItem($container, $account, $user, 'Växellådan');
    $bilaga = samladPapperskorgBilaga($levande, $account, $user, 'faktura.pdf', now()->subHours(2));

    $raderadContainer = samladPapperskorgContainer($account);
    samladPapperskorgRaderaContainer($raderadContainer, now()->subHour());

    $raderareAvBilaga = User::factory()->create(['name' => 'Bilage-Raderaren']);
    $raderareAvContainer = User::factory()->create(['name' => 'Container-Raderaren']);

    app(RecordAuditEvent::class)->handle(
        action: AuditLog::ACTION_ATTACHMENT_DELETED,
        user: $raderareAvBilaga,
        container: $container,
        item: $levande,
        subjectType: 'attachment',
        subjectUlid: $bilaga->ulid,
    );

    app(RecordAuditEvent::class)->handle(
        action: AuditLog::ACTION_CONTAINER_DELETED,
        user: $raderareAvContainer,
        container: $raderadContainer,
        subjectType: 'container',
        subjectUlid: $raderadContainer->ulid,
    );

    $svar = app(ListAllTrash::class)->handle($user, null, null, null);
    $poster = samladPapperskorgPoster($svar);

    expect($poster[$bilaga->ulid]['deleted_by'])->toBe('Bilage-Raderaren');
    expect($poster[$raderadContainer->ulid]['deleted_by'])->toBe('Container-Raderaren');
});

it('antalet frågor är konstant när rader och containrar blir fler', function () {
    Carbon::setTestNow('2026-10-18 12:00:00');

    try {
        [$account, $user] = samladPapperskorgKonto();
        $container = samladPapperskorgContainer($account);

        foreach (range(1, 3) as $i) {
            samladPapperskorgItem($container, $account, $user, "Första $i", now()->subMinutes($i));
        }

        // En raderad container i båda mätningarna: containerfrågans
        // eager-load ska räknas lika.
        $raderad = samladPapperskorgContainer($account);
        samladPapperskorgRaderaContainer($raderad, now()->subMinutes(5));

        $action = app(ListAllTrash::class);
        $scopes = app(ResolveItemScope::class);

        // Konto-cachen ligger kvar på User-instansen i båda mätningarna.
        // Omfångsmemon nollställs före varje mätning, annars hade den andra
        // mätningen fått den nya containern billigare än den första — det är
        // samma upplösning per request som mäts, inte memons träffbild.
        $user->load('accounts');

        DB::enableQueryLog();

        $scopes->flush();
        DB::flushQueryLog();
        $första = $action->handle($user, null, null, null);
        $frågorMedTrePoster = count(DB::getQueryLog());

        foreach (range(1, 10) as $i) {
            samladPapperskorgItem($container, $account, $user, "Fler $i", now()->subMinutes($i));
        }

        $andraContainer = samladPapperskorgContainer($account);

        foreach (range(1, 2) as $i) {
            samladPapperskorgItem($andraContainer, $account, $user, "Annan $i", now()->subMinutes($i));
        }

        $scopes->flush();
        DB::flushQueryLog();
        $andra = $action->handle($user, null, null, null);
        $frågorMedFlerRader = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($första['entries'])->toHaveCount(4);
        expect($andra['entries'])->toHaveCount(16);
        expect($frågorMedFlerRader)->toBe($frågorMedTrePoster);
    } finally {
        Carbon::setTestNow();
    }
});

it('indexet finns på audit_log', function () {
    $indexer = collect(Schema::getIndexes('audit_log'))
        ->mapWithKeys(fn (array $index) => [implode(',', $index['columns']) => $index['name']]);

    expect($indexer)->toHaveKey('subject_type,subject_id,created_at');
});

<?php

// rott-pa-basen: issue 512, ny yta — ingen befintlig test rörs.

use App\Actions\Agent\SetContainerAgentRules;
use App\Actions\Agent\SetUserAgentRules;
use App\Actions\OwnershipTransfer\AcceptOwnershipTransfer;
use App\Actions\Privacy\CollectPersonData;
use App\Actions\Trash\PurgeContainer;
use App\Models\AgentRulesRevision;
use App\Models\AuditLog;
use App\Models\Container;
use App\Models\User;
use App\Support\Access\ItemScope;
use App\Support\Api\ValidationErrorMapper;
use App\Support\Export\ContainerExportBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/*
 * Issue 512 · M95 Agentregler, ADR-0100. Lagringen: migration, actions,
 * behörighet, historik, ägarbyte, exporter och radering.
 *
 * "Klart när":
 * - migrationen går fram och tillbaka (sqlite)
 * - SetUserAgentRules: revision 1, sedan 2; samma text skriver ingen
 * - tom sträng och blanksteg sparas som NULL med en NULL-revision
 * - 2 001 tecken avvisas med validation.max och max 2000
 * - manageAgentRules: medlem ja; inbjuden, fryst konto och inbox nej
 * - containerns ändring loggas utan text
 * - ägarbyte nollställer reglerna och lämnar revisionerna
 * - exporterna bär reglerna
 * - PurgeContainer och personraderingen går igenom
 */

it('migrationen skapar kolumnerna och tabellen', function () {
    expect(Schema::hasColumn('user', 'agent_rules'))->toBeTrue()
        ->and(Schema::hasColumn('container', 'agent_rules'))->toBeTrue()
        ->and(Schema::hasColumns('agent_rules_revision', ['ulid', 'user_id', 'container_id', 'author_user_id', 'rules', 'revision', 'created_at']))->toBeTrue()
        ->and(Schema::hasColumn('agent_rules_revision', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('agent_rules_revision', 'deleted_at'))->toBeFalse();
});

it('SetUserAgentRules sparar texten och revision 1, sedan 2, och samma text en gång till skriver ingen revision', function () {
    $user = User::factory()->create();
    $action = app(SetUserAgentRules::class);

    expect($action->handle($user, 'Fråga innan du raderar.'))->toBe(1)
        ->and($user->fresh()->agent_rules)->toBe('Fråga innan du raderar.')
        ->and($action->handle($user, 'Fråga alltid.'))->toBe(2)
        ->and($action->handle($user, '  Fråga alltid.  '))->toBe(2);

    $revisioner = AgentRulesRevision::query()->where('user_id', $user->id)->orderBy('revision')->get();

    expect($revisioner)->toHaveCount(2)
        ->and($revisioner->pluck('revision')->all())->toBe([1, 2])
        ->and($revisioner[0]->rules)->toBe('Fråga innan du raderar.')
        ->and($revisioner[1]->author_user_id)->toBe($user->id)
        ->and(AuditLog::query()->count())->toBe(0);
});

it('tom sträng och bara blanksteg sparas som NULL med en revision vars rules är NULL', function () {
    $user = User::factory()->create();
    $action = app(SetUserAgentRules::class);
    $action->handle($user, 'Något.');

    expect($action->handle($user, " \n\t "))->toBe(2)
        ->and($user->fresh()->agent_rules)->toBeNull()
        ->and(AgentRulesRevision::query()->where('user_id', $user->id)->where('revision', 2)->value('rules'))->toBeNull()
        ->and($action->handle($user, ''))->toBe(2);
});

it('ett fält som aldrig satts har revision 0 och en tom text skriver ingen revision', function () {
    $user = User::factory()->create();

    expect(app(SetUserAgentRules::class)->handle($user, ''))->toBe(0)
        ->and(AgentRulesRevision::query()->count())->toBe(0);
});

it('2 001 tecken avvisas med validation.max och max 2000, 2 000 går igenom', function () {
    $user = User::factory()->create();

    try {
        app(SetUserAgentRules::class)->handle($user, str_repeat('å', 2001));
        $fel = null;
    } catch (ValidationException $e) {
        expect(ValidationErrorMapper::fields($e))->toBe([
            'rules' => [['code' => 'validation.max', 'data' => ['max' => 2000]]],
        ]);

        $fel = $e;
    }

    expect($fel)->toBeInstanceOf(ValidationException::class)
        ->and(AgentRulesRevision::query()->count())->toBe(0)
        ->and(app(SetUserAgentRules::class)->handle($user, str_repeat('å', 2000)))->toBe(1);
});

it('manageAgentRules tillåter ägarkontots medlem', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    expect($ägare->can('manageAgentRules', $container))->toBeTrue();
});

it('manageAgentRules nekar en inbjuden på varje nivå och oavsett kind', function () {
    [$konto] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    foreach (['member', 'guest'] as $kind) {
        foreach (['read', 'write'] as $level) {
            foreach (['read', 'create', 'write', 'delete'] as $agentLevel) {
                [, $inbjuden] = kontoMedMedlem();
                $access = beviljaAccess($container, $inbjuden, $level, $kind);
                $access->forceFill(['agent_level' => $agentLevel])->save();

                expect($inbjuden->can('manageAgentRules', $container))->toBeFalse("{$kind}/{$level}/{$agentLevel}");
            }
        }
    }
});

it('manageAgentRules nekar ett fryst ägarkonto och en inbox', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();

    $konto->forceFill(['status' => 'read_only'])->save();
    expect($ägare->fresh()->can('manageAgentRules', $container->fresh()))->toBeFalse();

    $konto->forceFill(['status' => 'closed'])->save();
    expect($ägare->fresh()->can('manageAgentRules', $container->fresh()))->toBeFalse();

    $konto->forceFill(['status' => 'active'])->save();
    $inbox = Container::factory()->for($konto, 'account')->create(['inbox_user_id' => $ägare->id]);
    expect($ägare->fresh()->can('manageAgentRules', $inbox))->toBeFalse();
});

it('en ändring av containerns regler loggas med revision i meta och utan texten', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    $action = app(SetContainerAgentRules::class);

    expect($action->handle($container, 'Hemlig regeltext.', $ägare))->toBe(1)
        ->and($action->handle($container, 'Hemlig regeltext.', $ägare))->toBe(1)
        ->and($action->handle($container, 'Ny hemlig text.', $ägare))->toBe(2)
        ->and($container->fresh()->agent_rules)->toBe('Ny hemlig text.');

    $rader = AuditLog::query()->where('action', 'container.agent_rules_updated')->orderBy('id')->get();

    expect($rader)->toHaveCount(2)
        ->and($rader[1]->meta)->toBe(['revision' => 2])
        ->and($rader[1]->user_id)->toBe($ägare->id)
        ->and(json_encode($rader->pluck('meta')))->not->toContain('hemlig');
});

it('ett godkänt ägarbyte nollställer containerns regler och lämnar revisionerna kvar', function () {
    [$säljare, $säljarAnv] = kontoMedMedlem();
    [$köpare] = kontoMedMedlem();
    $container = Container::factory()->for($säljare, 'account')->create();
    DB::table('usage_counter')->updateOrInsert(['account_id' => $säljare->id], ['container_count' => 1, 'storage_bytes' => 0, 'created_at' => now(), 'updated_at' => now()]);
    app(SetContainerAgentRules::class)->handle($container, 'Gamla regler.', $säljarAnv);

    $överföring = skapaÄgarbyteRad($container, ['to_account_id' => $köpare->id, 'to_email' => null]);
    app(AcceptOwnershipTransfer::class)->handle($överföring, $köpare);

    expect($container->fresh()->agent_rules)->toBeNull()
        ->and($container->fresh()->account_id)->toBe($köpare->id)
        ->and(AgentRulesRevision::query()->where('container_id', $container->id)->orderBy('revision')->get()->map(fn ($r) => [$r->revision, $r->rules, $r->author_user_id])->all())
        ->toBe([[1, 'Gamla regler.', $säljarAnv->id], [2, null, null]]);
});

it('personens regler och revisioner finns i CollectPersonData och containerns regler i containerexporten', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    app(SetUserAgentRules::class)->handle($ägare, 'Personens regel.');
    app(SetContainerAgentRules::class)->handle($container, 'Containerns regel.', $ägare);

    $data = app(CollectPersonData::class)->collect($ägare->id);

    expect($data['user']['agent_rules'])->toBe('Personens regel.')
        ->and($data['agent_rules_revision'])->toHaveCount(1)
        ->and($data['agent_rules_revision'][0]['rules'])->toBe('Personens regel.')
        ->and($data['agent_rules_revision'][0]['revision'])->toBe(1)
        ->and($data['agent_rules_revision'][0])->not->toHaveKey('id');

    $sökväg = tempnam(sys_get_temp_dir(), 'agentregler');
    $zip = new ZipArchive;
    $zip->open($sökväg, ZipArchive::OVERWRITE);
    app(ContainerExportBuilder::class)->addContainerTo($zip, $container, ItemScope::unrestricted('read'), $ägare, '');
    $zip->close();

    $läs = new ZipArchive;
    $läs->open($sökväg);
    $json = json_decode((string) $läs->getFromName('container.json'), true);
    $läs->close();
    unlink($sökväg);

    expect($json['container']['agent_rules'])->toBe('Containerns regel.')
        ->and(json_encode($json))->not->toContain('revision');
});

it('PurgeContainer går igenom med revisioner på plats', function () {
    [$konto, $ägare] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    app(SetContainerAgentRules::class)->handle($container, 'Regel.', $ägare);

    app(PurgeContainer::class)->handle($container);

    expect(Container::withTrashed()->whereKey($container->id)->exists())->toBeFalse()
        ->and(AgentRulesRevision::query()->where('container_id', $container->id)->count())->toBe(0);
});

it('personraderingen tar personens revisioner och nollställer författaren på en annans container', function () {
    [$konto, $ägare] = kontoMedMedlem();
    [, $annan] = kontoMedMedlem();
    $container = Container::factory()->for($konto, 'account')->create();
    app(SetContainerAgentRules::class)->handle($container, 'Skriven av annan.', $annan);
    app(SetUserAgentRules::class)->handle($annan, 'Personlig.');

    DB::table('account_user')->where('user_id', $annan->id)->delete();
    DB::table('personal_access_tokens')->where('tokenable_id', $annan->id)->delete();
    DB::table('user')->where('id', $annan->id)->delete();

    expect(AgentRulesRevision::query()->where('user_id', $annan->id)->count())->toBe(0)
        ->and(AgentRulesRevision::query()->where('container_id', $container->id)->value('author_user_id'))->toBeNull()
        ->and(AgentRulesRevision::query()->where('container_id', $container->id)->value('rules'))->toBe('Skriven av annan.');
});

it('migrationen går fram och tillbaka', function () {
    $migration = require base_path('database/migrations/2026_11_26_050000_add_agent_rules.php');

    $migration->down();

    expect(Schema::hasTable('agent_rules_revision'))->toBeFalse()
        ->and(Schema::hasColumn('user', 'agent_rules'))->toBeFalse()
        ->and(Schema::hasColumn('container', 'agent_rules'))->toBeFalse();

    $migration->up();

    expect(Schema::hasTable('agent_rules_revision'))->toBeTrue()
        ->and(Schema::hasColumn('container', 'agent_rules'))->toBeTrue();
});

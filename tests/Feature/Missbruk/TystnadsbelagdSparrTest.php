<?php

use App\Actions\Privacy\RecordPrivacyVerification;
use App\Models\Account;
use App\Models\LegalHold;
use App\Models\PrivacyRequest;
use App\Models\PrivacyRequestAttachment;
use App\Models\SecurityLog;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

/*
 * Issue 1693 · M91: tystnadsbelagda spärrar. Se App\Models\LegalHold,
 * App\Actions\LegalHold\PlaceLegalHold och MarkLegalHoldConfidential,
 * CollectPrivacyData och [[ADR-0043 Tre loggar]] § Den rättsliga spärren.
 *
 * Hjälparna har prefixet `tystnad` — Pests funktioner är globala.
 */

beforeEach(function () {
    Storage::fake('privacy');
});

/** @return array{0: Account, 1: User, 2: PrivacyRequest} */
function tystnadOpp(): array
{
    $konto = Account::factory()->create();
    $person = User::factory()->create();
    $konto->users()->attach($person, ['role' => 'owner']);

    $arende = PrivacyRequest::factory()->create([
        'user_id' => $person->id,
        'account_id' => $konto->id,
        'status' => PrivacyRequest::STATUS_IN_PROGRESS,
    ]);

    app(RecordPrivacyVerification::class)->handle(
        request: $arende,
        subject: 'identity',
        method: 'account_email',
        result: 'verified',
        note: null,
        actor: 'Anna Handläggare',
    );

    return [$konto, $person, $arende];
}

/** @return array{0: string, 1: array<string, mixed>} utdata och filens innehåll */
function tystnadKor(PrivacyRequest $arende): array
{
    Artisan::call('privacy:collect', ['reference' => $arende->reference, '--by' => 'Anna']);
    $utdata = Artisan::output();

    $bilaga = PrivacyRequestAttachment::query()->sole();
    $fil = Storage::disk('privacy')->get($arende->ulid.'/'.$bilaga->ulid);

    return [$utdata, json_decode($fil, true, flags: JSON_THROW_ON_ERROR)];
}

it('legal-hold:place sätter confidential false utan flaggan och true med den', function () {
    $a = Account::factory()->create();
    $b = Account::factory()->create();

    artisan('legal-hold:place', ['account' => $a->ulid, 'case' => 'A-1', 'reason' => 'x'])
        ->expectsOutputToContain('Not confidential: the hold will be disclosed in a reply under art. 15.')
        ->assertSuccessful();
    artisan('legal-hold:place', ['account' => $b->ulid, 'case' => 'B-1', 'reason' => 'x', '--confidential' => true])
        ->expectsOutputToContain('Confidential: the hold will not be disclosed in a reply under art. 15')
        ->assertSuccessful();

    expect(LegalHold::query()->where('account_id', $a->id)->sole()->confidential)->toBeFalse()
        ->and(LegalHold::query()->where('account_id', $b->id)->sole()->confidential)->toBeTrue();

    $rad = SecurityLog::query()->where('account_id', $b->id)->where('action', SecurityLog::ACTION_LEGAL_HOLD_PLACED)->sole();
    expect($rad->meta)->toBe(['case_number' => 'B-1', 'confidential' => true]);
});

it('legal-hold:confidential sätter true och loggar, okänt konto eller ärende ändrar inget', function () {
    $konto = Account::factory()->create();
    $hold = LegalHold::factory()->create(['account_id' => $konto->id, 'case_number' => 'C-1']);

    artisan('legal-hold:confidential', ['account' => 'finns-inte', 'case' => 'C-1'])->assertFailed();
    artisan('legal-hold:confidential', ['account' => $konto->ulid, 'case' => 'C-2'])->assertFailed();

    expect($hold->fresh()->confidential)->toBeFalse()
        ->and(SecurityLog::query()->where('action', SecurityLog::ACTION_LEGAL_HOLD_CONFIDENTIAL)->count())->toBe(0);

    artisan('legal-hold:confidential', ['account' => $konto->ulid, 'case' => 'C-1'])->assertSuccessful();

    expect($hold->fresh()->confidential)->toBeTrue();

    $rad = SecurityLog::query()->where('action', SecurityLog::ACTION_LEGAL_HOLD_CONFIDENTIAL)->sole();
    expect($rad->meta)->toBe(['case_number' => 'C-1'])
        ->and($rad->account_id)->toBe($konto->id);
});

it('privacy:collect tar med en spärr som inte är tystnadsbelagd, utan reason', function () {
    [$konto, , $arende] = tystnadOpp();

    artisan('legal-hold:place', ['account' => $konto->ulid, 'case' => 'D-1', 'reason' => 'Hemlig fritext'])->assertSuccessful();

    [$utdata, $data] = tystnadKor($arende);

    expect($utdata)->toContain('Legal hold active (will be disclosed)')
        ->and($data['legal_hold'])->toHaveCount(1)
        ->and($data['legal_hold'][0]['case_number'])->toBe('D-1')
        ->and($data['legal_hold'][0])->toHaveKeys(['created_at', 'lifted_at'])
        ->and(json_encode($data))->not->toContain('Hemlig fritext')
        ->and(array_column($data['security_log'], 'action'))->toBe([SecurityLog::ACTION_LEGAL_HOLD_PLACED]);
});

it('privacy:collect utelämnar en tystnadsbelagd spärr och dess rader', function () {
    [$konto, , $arende] = tystnadOpp();

    artisan('legal-hold:place', ['account' => $konto->ulid, 'case' => 'E-1', 'reason' => 'x', '--confidential' => true])->assertSuccessful();
    artisan('legal-hold:place', ['account' => $konto->ulid, 'case' => 'E-2', 'reason' => 'x'])->assertSuccessful();

    [$utdata, $data] = tystnadKor($arende);

    expect($utdata)->toContain('Legal hold active (confidential)')
        ->and(array_column($data['legal_hold'], 'case_number'))->toBe(['E-2'])
        ->and(json_encode($data['security_log']))->not->toContain('E-1')
        ->and(json_encode($data['security_log']))->toContain('E-2');
});

it('en hävd tystnadsbelagd spärr lämnar inga legal_hold-rader', function () {
    [$konto, , $arende] = tystnadOpp();

    artisan('legal-hold:place', ['account' => $konto->ulid, 'case' => 'G-1', 'reason' => 'x', '--confidential' => true])->assertSuccessful();
    artisan('legal-hold:lift', ['account' => $konto->ulid])->assertSuccessful();

    [, $data] = tystnadKor($arende);

    expect($data['legal_hold'])->toBe([])
        ->and(json_encode($data['security_log']))->not->toContain('legal_hold.');
});

it('två spärrar, en av varje, där den ena hävs, lämnar inga legal_hold-rader', function () {
    [$konto, , $arende] = tystnadOpp();

    artisan('legal-hold:place', ['account' => $konto->ulid, 'case' => 'H-1', 'reason' => 'x', '--confidential' => true])->assertSuccessful();
    artisan('legal-hold:place', ['account' => $konto->ulid, 'case' => 'H-2', 'reason' => 'x'])->assertSuccessful();
    artisan('legal-hold:lift', ['account' => $konto->ulid])->assertSuccessful();

    [, $data] = tystnadKor($arende);

    expect(json_encode($data['security_log']))->not->toContain('legal_hold.lifted')
        ->and(json_encode($data['security_log']))->not->toContain('H-1');
});

it('en spärr som markeras i efterhand utelämnas, också dess confidential-rad', function () {
    [$konto, , $arende] = tystnadOpp();

    artisan('legal-hold:place', ['account' => $konto->ulid, 'case' => 'F-1', 'reason' => 'x'])->assertSuccessful();
    artisan('legal-hold:confidential', ['account' => $konto->ulid, 'case' => 'F-1'])->assertSuccessful();

    [, $data] = tystnadKor($arende);

    expect($data['legal_hold'])->toBe([])
        ->and(json_encode($data['security_log']))->not->toContain('F-1');
});

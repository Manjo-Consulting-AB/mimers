<?php

use App\Models\Account;
use App\Models\User;

/*
 * Issue 5 · Magic link, uppföljning efter granskning av PR #34.
 * [[ADR-0013 Språk och i18n]] § Konsekvenser: serverrenderat innehåll
 * väljer språk från mottagarens `locale`, användarens åsidosätter kontots.
 * Se App\Models\User::preferredLocale().
 */

function kontoMedLocale(string $locale): Account
{
    return Account::factory()->create(['locale' => $locale]);
}

it('använder användarens egen locale när den är satt, oavsett konton', function () {
    $konto = kontoMedLocale('en_US');
    $user = User::factory()->create(['locale' => 'sv_SE']);
    $konto->users()->attach($user, ['role' => 'member']);

    expect($user->preferredLocale())->toBe('sv_SE');
});

it('faller tillbaka på kontots locale när användarens är NULL och personen har exakt ett konto', function () {
    $konto = kontoMedLocale('en_US');
    $user = User::factory()->create(['locale' => null]);
    $konto->users()->attach($user, ['role' => 'owner']);

    expect($user->preferredLocale())->toBe('en_US');
});

it('returnerar NULL — inte en gissning — när personen inte hör till något konto', function () {
    $user = User::factory()->create(['locale' => null]);

    expect($user->preferredLocale())->toBeNull();
});

it('returnerar NULL i stället för att gissa vilket konto som gäller, när personen hör till flera', function () {
    $förstaKontot = kontoMedLocale('en_US');
    $andraKontot = kontoMedLocale('sv_SE');
    $user = User::factory()->create(['locale' => null]);
    $förstaKontot->users()->attach($user, ['role' => 'member']);
    $andraKontot->users()->attach($user, ['role' => 'member']);

    expect($user->preferredLocale())->toBeNull();
});

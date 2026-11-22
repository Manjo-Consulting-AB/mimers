<?php

use App\Http\Resources\ContainerResource;
use App\Models\Container;
use App\Models\User;

/*
 * Issue 1521 · `locked` i listorna betyder exakt det scopet utesluter
 * (ADR-0092): med i `accessibleBy(includeLocked: true)` men inte i
 * `accessibleBy` utan den. Provet håller `ContainerResource::isLocked()`
 * till det, så att regeln inte glider isär från scopet.
 */

/**
 * @return array<int, bool> container-id → låst enligt scopet
 */
function lastFlaggaFranScopet(User $user): array
{
    $konton = $user->accounts->pluck('id')->all();
    $med = Container::query()->accessibleBy($user, $konton, includeLocked: true)->pluck('container.id')->all();
    $utan = Container::query()->accessibleBy($user, $konton)->pluck('container.id')->all();

    $svar = [];
    foreach ($med as $id) {
        $svar[$id] = ! in_array($id, $utan, true);
    }

    return $svar;
}

it('isLocked ger samma svar som skillnaden mellan scopen i varje kombination', function (bool $krav, bool $kontoKrav, bool $egen, bool $tvafaktor) {
    [$konto, $agare] = kontoMedMedlem();
    $konto->forceFill(['require_two_factor' => $kontoKrav])->save();
    [$annatKonto, $mottagare] = kontoMedMedlem();

    $delad = Container::factory()->for($konto, 'account')->create(['require_two_factor' => $krav]);
    beviljaAccess($delad, $mottagare, 'write', 'guest');
    $egenContainer = Container::factory()->for($annatKonto, 'account')->create(['require_two_factor' => $krav]);

    $anvandare = $egen ? $agare : $mottagare;
    $anvandare->forceFill(['totp_confirmed_at' => $tvafaktor ? now() : null])->save();
    $anvandare = $anvandare->fresh();

    $konton = $anvandare->accounts->pluck('id')->all();
    $fran = lastFlaggaFranScopet($anvandare);

    $rader = Container::query()->accessibleBy($anvandare, $konton, includeLocked: true)->get();
    expect($rader)->not->toBeEmpty();

    foreach ($rader as $rad) {
        expect(ContainerResource::isLocked($rad, $anvandare, $konton))->toBe($fran[$rad->id]);
    }

    unset($egenContainer);
})->with([
    'krav på containern, utan tvåfaktor' => [true, false, false, false],
    'krav på kontot, utan tvåfaktor' => [false, true, false, false],
    'inget krav' => [false, false, false, false],
    'krav, med tvåfaktor' => [true, true, false, true],
    'krav, ägarkontots medlem utan tvåfaktor' => [true, true, true, false],
]);

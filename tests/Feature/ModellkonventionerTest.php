<?php

use App\Models\Example;

/*
 * Issue 2 · Gemensamma modellkonventioner.
 *
 * Bevisar med Example — exempelmodellen som finns just för det här syftet —
 * att ULID, mjuk radering och tidsstämplar fungerar tillsammans enligt
 * AGENTS.md § Databaskonventioner och ADR-0008.
 */

it('sätter ulid, tidsstämplar och löpnummer när en rad skapas', function () {
    $example = Example::create(['name' => 'Segelbåt']);

    expect($example->id)->toBeInt();
    expect($example->ulid)->toBeString();
    expect($example->ulid)->not->toBeEmpty();
    expect(strlen($example->ulid))->toBe(26);
    expect($example->created_at)->not->toBeNull();
    expect($example->updated_at)->not->toBeNull();
    expect($example->deleted_at)->toBeNull();
});

it('genererar unika ulid för varje rad', function () {
    $first = Example::create(['name' => 'Första']);
    $second = Example::create(['name' => 'Andra']);

    expect($first->ulid)->not->toBe($second->ulid);
});

it('raderade rader kommer inte med i listning', function () {
    $synlig = Example::create(['name' => 'Kvar']);
    $raderad = Example::create(['name' => 'Borttagen']);

    $raderad->delete();

    $listning = Example::all();

    expect($listning)->toHaveCount(1);
    expect($listning->first()->is($synlig))->toBeTrue();
    expect(Example::find($raderad->id))->toBeNull();

    // Raden finns kvar i databasen — soft delete, inte hård radering.
    expect(Example::withTrashed()->count())->toBe(2);
    expect(Example::withTrashed()->find($raderad->id)->deleted_at)->not->toBeNull();
});

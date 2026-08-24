<?php

namespace App\Models;

use App\Models\Concerns\HasUlid;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Exempelmodell för issue 2 · Gemensamma modellkonventioner.
 *
 * Den representerar ingen domän — den finns bara för att bevisa att ULID,
 * mjuk radering och tidsstämplar fungerar tillsammans, enligt AGENTS.md
 * § Databaskonventioner. Riktiga modeller (konto, container, item, ...)
 * börjar i issue 3.
 */
#[Fillable(['name'])]
#[RouteKey('ulid')]
class Example extends Model
{
    use HasUlid, SoftDeletes;
}

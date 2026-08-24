<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Genererar en ULID i kolumnen `ulid` vid skapande.
 *
 * Löpnumret (`id`) förblir primärnyckel och används internt för relationer,
 * men `ulid` är identifieraren som exponeras utåt — se AGENTS.md
 * § Databaskonventioner och [[Datamodell – översikt]].
 *
 * Migrationen ansvarar för kolumnen (`char('ulid', 26)->unique()`), den här
 * traiten ansvarar bara för att fylla i värdet. Modellen väljer själv om den
 * även vill binda routes mot ulid, t.ex. med Laravels
 * `#[RouteKey('ulid')]`-attribut.
 */
trait HasUlid
{
    protected static function bootHasUlid(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->{$model->getUlidColumn()})) {
                $model->{$model->getUlidColumn()} = (string) Str::ulid();
            }
        });
    }

    /**
     * Namnet på kolumnen ULID:en lagras i. Overridas om en tabell av någon
     * anledning behöver ett annat kolumnnamn än konventionens `ulid`.
     */
    public function getUlidColumn(): string
    {
        return 'ulid';
    }
}

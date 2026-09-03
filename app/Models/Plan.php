<?php

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * En plan — vad ett konto får — se [[Planer och kvoter]] § plan. Gränserna
 * ligger i JSON-kolumnen `limits`; ett nytt B2B-erbjudande är en ny rad, inte
 * ny kod, och ingen `if`-sats nämner någonsin ett plannamn (issue 25 §
 * Beslut 2).
 *
 * Inget ulid och inget deleted_at: en plan syns aldrig i API:et som resurs —
 * identifieraren utåt är `code` — och en plan som ska bort sätts
 * `is_public = false` och blir kvar, så konton som ligger på den fortsätter
 * fungera (issue 25 § Beslut 1).
 *
 * `limits` är allt [[Planer och kvoter]] ställer in: nio nycklar, där
 * `null` betyder obegränsat. `planLimit()` är den enda vägen in i JSON:et —
 * en felstavad nyckel ska bli ett undantag här, inte en tyst `null` (som
 * betyder obegränsat) i någon kontroll (issue 25 § Beslut 7).
 */
#[Fillable(['code', 'name', 'price_amount', 'price_currency', 'billing_period', 'limits', 'is_public'])]
class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    /**
     * Tabellen heter `plan`, inte Eloquents standardplural.
     */
    protected $table = 'plan';

    /**
     * Get the attributes that should be cast.
     *
     * `limits` måste castas till array — i sqlite är json-kolumnen en
     * TEXT-kolumn och utan casten vore värdet en sträng i testerna (issue 25
     * § Att se upp med).
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_amount' => 'integer',
            'limits' => 'array',
            'is_public' => 'boolean',
        ];
    }

    /**
     * Läs en gräns ur planens `limits`-JSON.
     *
     * En nyckel som finns med värdet `null` betyder obegränsat och returnerar
     * `null`. En nyckel som inte finns alls är ett programmeringsfel och
     * kastar — annars vore varje stavfel i en kontroll en gratis Pro-plan
     * (issue 25 § Beslut 7 och § Att se upp med).
     */
    public function planLimit(string $key): int|bool|null
    {
        $limits = $this->limits ?? [];

        if (! array_key_exists($key, $limits)) {
            throw new InvalidArgumentException("Okänd plangräns [{$key}].");
        }

        return $limits[$key];
    }
}

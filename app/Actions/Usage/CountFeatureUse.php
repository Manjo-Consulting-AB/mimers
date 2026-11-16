<?php

namespace App\Actions\Usage;

use App\Models\User;
use App\Support\Usage\Features;
use App\Support\Usage\UsagePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Räknar upp en funktion i den anonyma mätningen ([[ADR-0079 Övervakning]] § 4,
 * issue 391). Anropas efter att handlingen lyckats. Ett okänt namn är ett
 * programmeringsfel och kastar; ett fel i själva uppräkningen loggas och sväljs
 * — mätningen får aldrig fälla handlingen.
 */
class CountFeatureUse
{
    public const SOURCE_ROUTE = 'route';

    public const SOURCE_FEATURE = 'feature';

    public function handle(string $name, ?User $user): void
    {
        if (! in_array($name, Features::all(), true)) {
            throw new InvalidArgumentException("Okänd funktion [{$name}]; namnen står i App\\Support\\Usage\\Features.");
        }

        try {
            self::increment(self::SOURCE_FEATURE, $name, UsagePlan::code($user));
        } catch (Throwable $e) {
            Log::warning('Uppräkningen av funktion misslyckades.', ['exception' => $e::class]);
        }
    }

    /**
     * Den enda upserten: en rad per (dag, källa, namn, plan).
     */
    public static function increment(string $source, string $name, string $plan): void
    {
        $count = DB::getQueryGrammar()->wrap('count');
        $now = now();

        DB::table('usage_event_daily')->upsert(
            [[
                'date' => $now->toDateString(),
                'source' => $source,
                'name' => $name,
                'plan' => $plan,
                'count' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['date', 'source', 'name', 'plan'],
            ['count' => DB::raw($count.' + 1'), 'updated_at' => $now],
        );
    }
}

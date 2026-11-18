<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Issue 399 (M65) · Pro, Pro+ och grundarpris. Se [[ADR-0086 Pro, Pro+ och
 * grundarpris]] § 1, 2 och 4 samt [[Planer och kvoter]] § plan.
 *
 * `pro` sänks till 10 GB och 3900 EUR; `pro_plus`, `pro_founder` och
 * `pro_plus_founder` läggs till. De tre nya raderna kopierar `limits` ur
 * `pro`-raden i databasen och ändrar bara `storage_bytes`, så att
 * `pending_invitations` och framtida nycklar följer med. Inga
 * subscription-rader flyttas (ADR-0086 § Konsekvenser).
 *
 * Rader läses och skrivs med DB::table, aldrig via modellen, och
 * insättningen är idempotent via updateOrInsert på `code`.
 */
return new class extends Migration
{
    private const GB = 1024 * 1024 * 1024;

    /** @var array<string, array{name: string, storage: int, price: int, public: bool}> */
    private const NYA = [
        'pro_plus' => ['name' => 'Pro+', 'storage' => 50, 'price' => 6900, 'public' => true],
        'pro_founder' => ['name' => 'Pro', 'storage' => 10, 'price' => 1900, 'public' => false],
        'pro_plus_founder' => ['name' => 'Pro+', 'storage' => 50, 'price' => 3900, 'public' => false],
    ];

    public function up(): void
    {
        $pro = DB::table('plan')->where('code', 'pro')->first();

        if ($pro === null) {
            return;
        }

        $nu = now();
        $limits = json_decode($pro->limits, true, flags: JSON_THROW_ON_ERROR);
        $limits['storage_bytes'] = 10 * self::GB;

        DB::table('plan')->where('code', 'pro')->update([
            'price_amount' => 3900,
            'limits' => json_encode($limits, JSON_THROW_ON_ERROR),
            'updated_at' => $nu,
        ]);

        foreach (self::NYA as $code => $rad) {
            $radLimits = $limits;
            $radLimits['storage_bytes'] = $rad['storage'] * self::GB;

            DB::table('plan')->updateOrInsert(
                ['code' => $code],
                [
                    'name' => $rad['name'],
                    'price_amount' => $rad['price'],
                    'price_currency' => 'EUR',
                    'billing_period' => 'year',
                    'limits' => json_encode($radLimits, JSON_THROW_ON_ERROR),
                    'is_public' => $rad['public'],
                    'created_at' => $nu,
                    'updated_at' => $nu,
                ],
            );
        }
    }

    public function down(): void
    {
        $pro = DB::table('plan')->where('code', 'pro')->first();

        if ($pro !== null) {
            $limits = json_decode($pro->limits, true, flags: JSON_THROW_ON_ERROR);
            $limits['storage_bytes'] = 25 * self::GB;

            DB::table('plan')->where('code', 'pro')->update([
                'price_amount' => 4900,
                'limits' => json_encode($limits, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }

        DB::table('plan')->whereIn('code', array_keys(self::NYA))->delete();
    }
};

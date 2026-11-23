<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 453 · M76. Se [[ADR-0093 Ägarbytets bonus till nya kunder]] och
 * [[Planer och kvoter]] § subscription.
 *
 * `subscription.source` säger vad som gav kontot dess nuvarande plan:
 * `paid` | `transfer_bonus` | `voucher` | `internal`. Ingen regel läser
 * kolumnen än; det gör issue 454.
 *
 * Kolumnen läggs till med förvalet `voucher` och befintliga rader fylls i
 * därefter, i ordning: `internal` där `external_ref = 'internal'`, annars
 * `transfer_bonus` där kontots `transfer_bonus_granted_at` är satt, annars
 * `voucher`. Ingen befintlig rad blir `paid` — betalningen är inte kopplad
 * än, och det är avsiktligt.
 *
 * CHECK-villkoret läggs bara på mysql, som `subscription_status_check`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription', function (Blueprint $table) {
            $table->string('source', 20)->default('voucher')->after('status');
        });

        DB::table('subscription')
            ->where('external_ref', 'internal')
            ->update(['source' => 'internal']);

        DB::table('subscription')
            ->where(function ($q): void {
                $q->whereNull('external_ref')->orWhere('external_ref', '!=', 'internal');
            })
            ->whereIn('account_id', function ($q): void {
                $q->select('id')->from('account')->whereNotNull('transfer_bonus_granted_at');
            })
            ->update(['source' => 'transfer_bonus']);

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE subscription ADD CONSTRAINT subscription_source_check CHECK (source IN ('paid', 'transfer_bonus', 'voucher', 'internal'))");
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE subscription DROP CONSTRAINT subscription_source_check');
        }

        Schema::table('subscription', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};

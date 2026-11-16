<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 391 · Sidvisningar och funktioner, [[ADR-0079 Övervakning]] § 4.
 * Råtabellen är en mellanlagring: ett tal per dag, källa, namn och plan. Nattjobbet
 * App\Console\AggregatesUsageMetrics flyttar raderna till `usage_metric` med
 * tröskeln fem och tömmer dem.
 *
 * **Ingen användare, inget konto och ingen IP-adress.** Inget `user_id`,
 * `account_id` eller `ip` — en rad som kunde knytas till en person vore en
 * personuppgift, och hela poängen är att mätningen inte kräver samtycke.
 *
 * `source` är `route` eller `feature`; `name` är ruttnamnet respektive en
 * konstant ur App\Support\Usage\Features; `plan` är planens kod eller `guest`.
 * Det unika indexet gör att uppräkningen är en enda upsert.
 *
 * `usage_metric` får samtidigt vidgat CHECK-villkor för de två nya källorna.
 * CHECK-villkor läggs bara på mysql (sqlite saknar ALTER ... ADD CONSTRAINT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_event_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('source', 20);
            $table->string('name', 60);
            $table->string('plan', 40);
            $table->unsignedInteger('count');
            $table->timestamps();

            $table->unique(['date', 'source', 'name', 'plan']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE usage_event_daily ADD CONSTRAINT usage_event_daily_source_check CHECK (source IN ('route', 'feature'))");
            DB::statement('ALTER TABLE usage_metric DROP CONSTRAINT usage_metric_source_check');
            DB::statement("ALTER TABLE usage_metric ADD CONSTRAINT usage_metric_source_check CHECK (source IN ('audit', 'security', 'route', 'feature'))");
        }
    }

    /**
     * Migrationer rullas aldrig tillbaka i produktion; down() finns för testmiljön.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE usage_metric DROP CONSTRAINT usage_metric_source_check');
            DB::statement("ALTER TABLE usage_metric ADD CONSTRAINT usage_metric_source_check CHECK (source IN ('audit', 'security'))");
        }

        Schema::dropIfExists('usage_event_daily');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 25 · Planer och rättigheter. Se [[Planer och kvoter]] § subscription.
 *
 * `subscription` knyter ett konto till en plan. `account_id` är unikt — ett
 * konto har högst en prenumeration, statusen bär livscykeln (issue 25 §
 * Beslut 5). `grace_until` finns som kolumn och ingenting mer i den här
 * issuen; nedgraderingen är 28 och betalflödet (som skriver `external_ref`)
 * finns inte. `external_ref` står tom i MVP.
 *
 * `ulid` CHAR(26) eftersom prenumerationen hör till ett konto och kan visas i
 * en kontovy — den identifieras aldrig med ett löpnummer utåt (issue 25 §
 * Beslut 1). `current_period_end` är inte null; `grace_until` och
 * `external_ref` är null tills respektive förlopp finns.
 *
 * CHECK-villkoret läggs bara på mysql; sqlite (test) saknar stöd för ALTER
 * TABLE ... ADD CONSTRAINT. Uppräkningen är VARCHAR, aldrig MySQL ENUM.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('subscription', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('account_id')->unique()->constrained('account')->onDelete('restrict');
            $table->foreignId('plan_id')->constrained('plan')->onDelete('restrict');
            $table->string('status', 20);
            $table->timestamp('current_period_end');
            $table->timestamp('grace_until')->nullable();
            $table->string('external_ref', 191)->nullable();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE subscription ADD CONSTRAINT subscription_status_check CHECK (status IN ('active', 'past_due', 'cancelled'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 30 · En rad per notis och kanal. Se [[Notiser]] § notification_delivery
 * och [[ADR-0010 Notisarkitektur]] § Konsekvenser. Det är här idempotensen
 * bor: unik `(notification_id, channel)` — samma notis kan aldrig få två
 * leveransrader på samma kanal, oavsett om `dedupe_key` är satt eller inte
 * (issue 30 § Beslut 7).
 *
 * Kolumnerna är dokumentets, i den ordningen (Beslut 1) — inget mer: ingen
 * `deleted_at` (Beslut 2). `updated_at` finns trots att dokumentets lista bara
 * nämner `created_at` — AGENTS.md § Databaskonventioner kräver båda på allt,
 * och på just den här tabellen är kolumnen nödvändig: 37b räknar
 * omförsökens backoff som "senaste försöket plus väntetid", och senaste
 * försöket ÄR `updated_at` (Beslut 3).
 *
 * `channel` och `status` är slutna mängder och får sina CHECK-villkor
 * (Beslut 4) — VARCHAR med CHECK, aldrig MySQL ENUM
 * (AGENTS.md § Databaskonventioner). CHECK-villkoren läggs bara på mysql;
 * sqlite (testsviten) saknar stöd för ALTER TABLE ... ADD CONSTRAINT — se
 * förlagan 2026_09_03_000000_create_schedule_occurrence_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_delivery', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained('notification')->onDelete('restrict');
            $table->string('channel', 20);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'channel']);
            $table->index(['status', 'channel']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE notification_delivery ADD CONSTRAINT notification_delivery_channel_check CHECK (channel IN ('email', 'webhook'))");
            DB::statement("ALTER TABLE notification_delivery ADD CONSTRAINT notification_delivery_status_check CHECK (status IN ('pending', 'sent', 'failed', 'suppressed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_delivery');
    }
};

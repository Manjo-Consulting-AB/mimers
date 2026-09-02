<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 22a · Förekomster. Se [[Scheman och uppgifter]] § schedule_occurrence
 * och [[ADR-0005 Schema och förekomst]].
 *
 * Kolumnerna är dokumentets, i den ordningen (issue 22 § Beslut 2) — inget
 * mer: ingen `overdue`-kolumn (förfallen härleds: `status = 'open' AND
 * due_at < CURDATE()`, se Beslut 2) och ingen `deleted_at`. Tabellen är
 * systemgenererad bokföring, inte användarskapat innehåll: användaren raderar
 * ett SCHEMA, och förekomsterna följer med genom relationen — samma undantag
 * från soft-delete-konventionen som `item_link`, se [[Datamodell – översikt]].
 * Indexen bär därför inte `deleted_at`.
 *
 * Avslutskolumnerna (`completed_at`, `completed_by_user_id`,
 * `completed_by_account_id`, `completion_note`) finns redan här — de skrivs
 * av ingenting i den här issuen; avslutsflödet är 22b.
 *
 * `status` är VARCHAR med CHECK-villkor, aldrig MySQL ENUM
 * (AGENTS.md § Databaskonventioner). CHECK-villkoret läggs bara på mysql;
 * sqlite (testsviten) saknar stöd för ALTER TABLE ... ADD CONSTRAINT — se
 * förlagan 2026_08_31_130000_create_item_link_table.php.
 *
 * Dokumentets två index, plus ingenting (Beslut 2): `(schedule_id, status)`
 * för läsningen av ett schemas förekomster, `(due_at, status)` för
 * todo-listan över alla containers (issue 24). Inga `ENUM`, inga partiellt
 * unika index (Beslut 7) — invarianten "exakt en öppen förekomst per aktivt
 * schema" hålls i kod av App\Actions\Schedule\OpenNextOccurrence genom lås
 * på schemaraden, aldrig i tabellen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule_occurrence', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('schedule_id')->constrained('schedule')->onDelete('restrict');
            $table->date('visible_from');
            $table->date('due_at');
            $table->string('status', 20);
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by_user_id')->nullable()->constrained('user')->onDelete('restrict');
            $table->foreignId('completed_by_account_id')->nullable()->constrained('account')->onDelete('restrict');
            $table->text('completion_note')->nullable();
            $table->timestamps();

            $table->index(['schedule_id', 'status']);
            $table->index(['due_at', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE schedule_occurrence ADD CONSTRAINT schedule_occurrence_status_check CHECK (status IN ('open', 'completed', 'skipped'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule_occurrence');
    }
};

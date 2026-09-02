<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 21 · Scheman. Se [[Scheman och uppgifter]] § schedule och
 * [[ADR-0005 Schema och förekomst]].
 *
 * Kolumnerna är dokumentets, i den ordningen — inget mer: ingen
 * `next_due_at`, ingen `last_completed_at`, ingen `container_id`. Nästa
 * förfall bor på den öppna förekomsten (issue 22a); en kolumn här vore en
 * andra sanning. Containern härleds ur itemet, som aldrig byter container
 * (issue 21 § Beslut 3).
 *
 * `recurrence_type` och `interval_unit` är VARCHAR med CHECK-villkor, aldrig
 * MySQL ENUM (AGENTS.md § Databaskonventioner). CHECK-villkoren läggs bara
 * på mysql; sqlite (testsviten) saknar stöd för ALTER TABLE ... ADD
 * CONSTRAINT — valideringen bär hela ansvaret för uppräkningarna där, se
 * förlagan 2026_08_31_130000_create_item_link_table.php.
 *
 * `anchor_date` är nullbar i tabellen (dokumentet skriver den så) men
 * obligatorisk i valideringen — se issue 21 § Beslut 5. `lead_days` har
 * default 0 och ett tak på 365 i valideringen, se § Beslut 6.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('schedule', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('item_id')->constrained('item')->onDelete('restrict');
            $table->string('title', 255);
            $table->text('notes')->nullable();
            $table->string('recurrence_type', 20);
            $table->string('interval_unit', 10)->nullable();
            $table->unsignedSmallInteger('interval_count')->nullable();
            $table->date('anchor_date')->nullable();
            $table->unsignedSmallInteger('lead_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['item_id', 'deleted_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE schedule ADD CONSTRAINT schedule_recurrence_type_check CHECK (recurrence_type IN ('none', 'fixed', 'interval'))");
            DB::statement("ALTER TABLE schedule ADD CONSTRAINT schedule_interval_unit_check CHECK (interval_unit IN ('day', 'week', 'month', 'year'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('schedule');
    }
};

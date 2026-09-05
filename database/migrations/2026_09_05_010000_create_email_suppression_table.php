<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 33a · Undertryckta adresser. Se [[Notiser]] § email_suppression.
 * Tabellen matas av Postmarks webhook i 33b; den här issuen bygger tabellen,
 * uppslaget och spärren i e-postkanalen.
 *
 * Kolumnerna är dokumentets, i den ordningen (Beslut 1) — inget mer: ingen
 * `ulid` (raden syns aldrig i API:et), ingen `deleted_at` (en undertryckning
 * som ska bort tas bort på riktigt) och ingen `account_id` eller `user_id`
 * (undertryckningen gäller adressen, inte personen). `updated_at` finns trots
 * att dokumentets lista bara nämner `created_at` — AGENTS.md
 * § Databaskonventioner kräver båda på allt.
 *
 * `reason` är en sluten mängd — Postmarks tre utfall och ingenting annat
 * (Beslut 1) — och får sitt CHECK-villkor. CHECK-villkoret läggs bara på
 * mysql; sqlite (testsviten) saknar stöd för ALTER TABLE ... ADD CONSTRAINT
 * — se förlagan 2026_09_04_010000_create_notification_delivery_table.php.
 *
 * Uniknyckeln är på `email`, inte på `(email, reason)` (Beslut 1): en adress
 * har ett skäl i taget, och att den både studsat och anmälts som skräp ändrar
 * inget för leveransen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_suppression', function (Blueprint $table) {
            $table->id();
            $table->string('email', 255)->unique();
            $table->string('reason', 40);
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE email_suppression ADD CONSTRAINT email_suppression_reason_check CHECK (reason IN ('hard_bounce', 'spam_complaint', 'unsubscribe'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_suppression');
    }
};

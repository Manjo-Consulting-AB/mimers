<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 43 · Dead man's switch — en rad per schemapost, uppdaterad på plats
 * av App\Listeners\RecordsScheduleHeartbeat, se issue 43 § Beslut 2.
 *
 * Inget `ulid`: raden syns aldrig i API:et och har ingen extern identifierare —
 * ytan GET /drift/heartbeat lämnar ut namn och tidsstämpel, ingenting annat
 * (Beslut 2). Inget `deleted_at`: det här är drifttillstånd, inte
 * användarskapat innehåll — samma undantag som `webhook_delivery`.
 * `name` är schemapostens `->name(...)` (routes/console.php), alltså
 * `deliver-notifications`, `drain-queue` och de andra. Uniknyckeln är det som
 * gör att en andra körning uppdaterar raden i stället för att skapa en ny:
 * tabellen växer aldrig.
 *
 * `last_success_at` sätts av lyssnaren bara när körningen gick klar utan fel
 * (exitCode 0, Beslut 5). Förlagan är
 * 2026_09_06_010000_create_webhook_delivery_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('heartbeat', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->timestamp('last_success_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('heartbeat');
    }
};

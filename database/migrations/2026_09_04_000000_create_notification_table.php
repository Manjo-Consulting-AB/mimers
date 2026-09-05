<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 30 · Notisens utboksrad. Se [[Notiser]] § notification och
 * [[ADR-0010 Notisarkitektur]].
 *
 * Kolumnerna är dokumentets, i den ordningen (issue 30 § Beslut 1) — inget
 * mer: ingen `deleted_at` (Beslut 2). En notis är systemgenererad bokföring,
 * inte användarskapat innehåll — samma undantag från soft-delete-konventionen
 * som `schedule_occurrence`, se [[Datamodell – översikt]] § Konventioner.
 * Indexen bär därför inte `deleted_at`.
 *
 * `type` får INGET CHECK-villkor (Beslut 4): listan i [[Notiser]] är ett
 * namnrum med sju kända värden, men den är öppen med flit — `loan.due`
 * byggs i M6 och M8 kan lägga fler. Värdena bor som konstanter på
 * App\Models\Notification, så generatorerna (34b) aldrig stavar en sträng.
 *
 * `nullableMorphs('subject')` skapar sina egna kolumner och sitt eget index —
 * inget ytterligare läggs (issue 30 § Att se upp med).
 *
 * `dedupe_key` är nullable och unik samtidigt (Beslut 7): flera NULL bryter
 * inte en uniknyckel, i MySQL eller sqlite.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->nullable()->constrained('user')->onDelete('restrict');
            $table->foreignId('account_id')->constrained('account')->onDelete('restrict');
            $table->foreignId('container_id')->nullable()->constrained('container')->onDelete('restrict');
            $table->string('type', 60);
            $table->nullableMorphs('subject');
            $table->json('payload');
            $table->string('dedupe_key', 191)->nullable()->unique();
            $table->timestamp('available_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification');
    }
};

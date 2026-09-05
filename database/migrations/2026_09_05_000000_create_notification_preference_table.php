<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 31a · Vad användaren vill ha, per typ och kanal. Se
 * [[Notiser]] § notification_preference.
 *
 * Kolumnerna är dokumentets, i den ordningen (Beslut 1) — inget mer: ingen
 * `deleted_at` (Beslut 1), för ett bortvalt val är en rad med
 * `enabled = false`, inte en mjukraderad rad. Ingen `ulid` — raden syns
 * aldrig som en egen resurs i API:et, den identifieras av sin trippel
 * `(user_id, type, channel)` som klienten redan känner till (Beslut 1).
 *
 * `channel` är en sluten mängd och får sitt CHECK-villkor (Beslut 1).
 * CHECK-villkoret läggs bara på mysql; sqlite (testsviten) saknar stöd för
 * ALTER TABLE ... ADD CONSTRAINT — se förlagan
 * 2026_09_04_010000_create_notification_delivery_table.php.
 *
 * `type` får INGET CHECK-villkor, av samma skäl som i issue 30 § Beslut 4:
 * listan i [[Notiser]] är ett öppet namnrum, och ett CHECK-villkor skulle
 * kräva en migration varje gång M8 lägger till en typ.
 *
 * Raderna skapas aldrig vid registrering — en saknad rad betyder förvalt
 * värde i kod, och förvalen bor i
 * App\Support\Notification\NotificationPreferences (Beslut 2).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('notification_preference', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('type', 60);
            $table->string('channel', 20);
            $table->boolean('enabled');
            $table->boolean('digest');
            $table->timestamps();

            $table->unique(['user_id', 'type', 'channel']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE notification_preference ADD CONSTRAINT notification_preference_channel_check CHECK (channel IN ('email', 'webhook'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notification_preference');
    }
};

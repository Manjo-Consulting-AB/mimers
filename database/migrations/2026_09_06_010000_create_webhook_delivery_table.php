<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 37b · Leveransen — en rad per endpoint och notis, se [[Notiser]] §
 * Webhooks och App\Console\DeliversWebhooks.
 *
 * Beslut 1 avgör en motsägelse i dokumentationen: [[Notiser]] §
 * notification_delivery ger kanalen `webhook` med UNIQUE (notification_id,
 * channel) — en leverans per notis — medan [[Datamodell – översikt]] ritar
 * `webhook_endpoint → webhook_delivery` som en egen kedja. De går inte att
 * förena: ett konto kan ha FLERA endpoints som alla ska få samma händelse.
 * Den här tabellen är den egna kedjan; `notification_delivery` behåller sitt
 * CHECK-villkor med `'webhook'` men inga rader skrivs där för webhooks.
 *
 * `ulid` finns för att en leverans ska gå att peka ut i en supportfråga utan
 * att löpnumret läcker (Beslut 1). Inget `deleted_at`: raderna är
 * systemgenererad bokföring, och städningen vid kontoradering ligger i
 * App\Actions\Account\DeleteAccount.
 *
 * Båda nycklarna är ON DELETE RESTRICT: en endpoint (eller notis) som är part
 * i en leverans kan inte hårdraderas utan att leveransraden först är borta.
 * Det är därför DeleteAccount (Beslut 10) tar leveransraderna före
 * endpointsen OCH före notisraderna, och PurgeContainer behöver motsvarande
 * städning för container-notiser — se Frågor och antaganden i PR:en.
 *
 * `status` är en sluten mängd och får sitt CHECK-villkor (Beslut 1) — VARCHAR
 * med CHECK, aldrig MySQL ENUM (AGENTS.md § Databaskonventioner). Villkoret
 * läggs bara på mysql; sqlite (testsviten) saknar stöd för ALTER TABLE ...
 * ADD CONSTRAINT — se förlagan
 * 2026_09_04_010000_create_notification_delivery_table.php. Ingen
 * `suppressed`: undertryckning gäller e-postadresser (33a), inte webhooks.
 *
 * Indexen namnges explicit: MySQL har ett tak på 64 tecken för indexnamn, och
 * Laravels härledda `{tabell}_{kolumner}_{typ}`-namn på den här tabellen
 * spränger det — precis det fel occurrence_dependency fick rättat i teknisk
 * skuld-issue #136. UNIQUE (webhook_endpoint_id, notification_id): samma
 * händelse ska inte kunna fläkas ut två gånger till samma endpoint.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_delivery', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('webhook_endpoint_id')->constrained('webhook_endpoint')->onDelete('restrict');
            $table->foreignId('notification_id')->constrained('notification')->onDelete('restrict');
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->unique(['webhook_endpoint_id', 'notification_id'], 'webhook_delivery_endpoint_notification_unique');
            $table->index(['status', 'next_attempt_at'], 'webhook_delivery_status_next_attempt_index');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE webhook_delivery ADD CONSTRAINT webhook_delivery_status_check CHECK (status IN ('pending', 'sent', 'failed'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery');
    }
};

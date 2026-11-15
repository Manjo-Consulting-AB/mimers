<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 383 · Kanalen `in_app`: läst-läget och indexet som bär listan. Se
 * [[ADR-0077 Notislistan i appen]] § 1, 5 och 6 samt [[Notiser]]
 * § notification_delivery.
 *
 * `read_at` är kanalens egen tidsstämpel — `NULL` betyder oläst, och kolumnen
 * används bara av `in_app`. Den läggs på leveransraden och inte på notisen:
 * läget hör till leveransen, precis som `sent_at` och `status`, och en notis
 * kan ha både en e-postrad och en `in_app`-rad med olika liv.
 *
 * **Indexet.** Frågan är *"olästa `in_app`-rader för en person, nyast
 * först"*: personen och ordningen bor på `notification`, och `notification`
 * bär redan `(user_id, created_at)` sedan tabellen skapades. Det som saknades
 * är leveranssidan: `(channel, read_at, notification_id)` låter databasen
 * filtrera bort allt utom `in_app` och olästa innan den går tillbaka till
 * notisen, i stället för att läsa varje leveransrad för sig. Kolumnordningen
 * är likhetsvillkoren först (`channel`, `read_at`) och nyckeln till joinen
 * sist (`notification_id`), samma form som de andra indexen på tabellen.
 *
 * **CHECK-villkoret för `channel` måste rymma `in_app`.** Det skrevs i
 * 2026_09_04_010000 och räknar upp de giltiga kanalerna; utan en ändring
 * hade MariaDB avvisat varje `in_app`-rad i drift trots att sqlite-sviten
 * (som saknar CHECK) hade varit grön. Samma sak gäller
 * `notification_preference`: issue 386 skriver `in_app`-rader där, och den
 * issuen har `database/**` utanför sin ruta — därför ändras villkoret här.
 *
 * Droppen skrivs `DROP CONSTRAINT` och inte `DROP CHECK` (MariaDB 10.6, se
 * 2026_09_18_000000_free_container_kind.php). CHECK-villkoren finns bara på
 * mysql; sqlite klarar inte `ALTER TABLE ... DROP CONSTRAINT` (samma
 * drivrutinsvakt som förlagen).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_delivery', function (Blueprint $table) {
            $table->timestamp('read_at')->nullable()->after('claimed_at');

            $table->index(
                ['channel', 'read_at', 'notification_id'],
                'notification_delivery_unread_in_app_index',
            );
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE notification_delivery DROP CONSTRAINT notification_delivery_channel_check');
            DB::statement("ALTER TABLE notification_delivery ADD CONSTRAINT notification_delivery_channel_check CHECK (channel IN ('email', 'webhook', 'in_app'))");

            DB::statement('ALTER TABLE notification_preference DROP CONSTRAINT notification_preference_channel_check');
            DB::statement("ALTER TABLE notification_preference ADD CONSTRAINT notification_preference_channel_check CHECK (channel IN ('email', 'webhook', 'in_app'))");
        }
    }

    /**
     * Tillbaka till kanalerna före `in_app`. `read_at` och indexet försvinner
     * med kolumnen; en `in_app`-rad som redan skrivits ryms inte i det gamla
     * CHECK-villkoret och får städas först. Migrationer rullas aldrig tillbaka
     * i produktion (AGENTS.md § Databaskonventioner) — det här är för
     * utvecklingsmaskinen.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE notification_delivery DROP CONSTRAINT notification_delivery_channel_check');
            DB::statement("ALTER TABLE notification_delivery ADD CONSTRAINT notification_delivery_channel_check CHECK (channel IN ('email', 'webhook'))");

            DB::statement('ALTER TABLE notification_preference DROP CONSTRAINT notification_preference_channel_check');
            DB::statement("ALTER TABLE notification_preference ADD CONSTRAINT notification_preference_channel_check CHECK (channel IN ('email', 'webhook'))");
        }

        Schema::table('notification_delivery', function (Blueprint $table) {
            $table->dropIndex('notification_delivery_unread_in_app_index');
            $table->dropColumn('read_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 35 · Markeringen som gör en notis till en del av veckosammanfattningen
 * i stället för ett direkt mejl. Se [[Notiser]] § notification_preference och
 * § notification_delivery samt Beslut 1 i issuen.
 *
 * `digest` fryses in när notisen skapas (Beslut 1): CreateNotification läser
 * preferensen och skriver värdet på leveransraden, och varken leveransloopen
 * (34a) eller veckojobbet (SendsWeeklyDigest) räknar om det. Samma resonemang
 * som `available_at` (31a § Beslut 5): kön läser en kolumn, den räknar inte
 * om ett beslut.
 *
 * Migrationen är additiv med ett förval — expand/contract,
 * AGENTS.md § Databaskonventioner. Befintliga rader får `digest = false` och
 * levereras precis som förut av minutloopen; inga rader uppdateras här.
 *
 * Indexet på `(digest, status, channel)` är det SendsWeeklyDigest och
 * DeliversNotifications frågar på: det ena jobbet vill ha `digest = true` och
 * det andra `digest = false`, båda bland `pending`-e-postrader. Det befintliga
 * indexet på `(status, channel)` täcker inget av de två urvalen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('notification_delivery', function (Blueprint $table) {
            $table->boolean('digest')->default(false)->after('channel');
            $table->index(['digest', 'status', 'channel']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('notification_delivery', function (Blueprint $table) {
            $table->dropIndex(['digest', 'status', 'channel']);
            $table->dropColumn('digest');
        });
    }
};

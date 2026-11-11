<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 1056 · Markeringen att en leveransrad är tagen i anspråk av en
 * leveransloop. Se [[Notiser]] § notification_delivery och
 * App\Console\DeliversNotifications.
 *
 * Mejlet skickas utanför databastransaktionen; `claimed_at` är det som
 * hindrar en överlappande körning från att skicka samma rad medan nätverks-
 * anropet pågår. Ingen ny status, så CHECK-villkoren rörs inte. En rad vars
 * körning dog blir ledig igen när claimed_at är äldre än tio minuter. Inget
 * index: urvalet går redan på (status, channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_delivery', function (Blueprint $table) {
            $table->timestamp('claimed_at')->nullable()->after('sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('notification_delivery', function (Blueprint $table) {
            $table->dropColumn('claimed_at');
        });
    }
};

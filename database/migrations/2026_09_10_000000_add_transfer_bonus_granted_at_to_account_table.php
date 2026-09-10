<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 49 (M9) · Mottagarkontots spärr mot att ta emot ägarbytesbonusen
 * fler än en gång. Se [[ADR-0017 Missbruksvektorer]] § 4 Ägarbytesbonusen.
 *
 * `transfer_bonus_granted_at` är NULL så länge kontot aldrig tagit emot
 * ett ägarbyte. App\Actions\OwnershipTransfer\AcceptOwnershipTransfer::
 * beviljaPro() sätter den med en villkorad UPDATE (Beslut 3) i samma
 * transaktion som ägarbytet: returnerar UPDATE:n 0 rader har kontot redan
 * konsumerat bonusen och Pro-tiden uteblir — ägarbytet går igenom ändå.
 *
 * Kolumnen läses bara med primärnyckeln i handen, så den får inget index.
 * Ingen backfill (Beslut 2): underlaget finns inte, eftersom ett ägarbyte
 * som gått via `to_email` aldrig skriver tillbaka mottagarkontots id — att
 * gissa vore att stjäla en bonus från ett konto som aldrig fått den.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->timestamp('transfer_bonus_granted_at')->nullable()->after('read_only_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->dropColumn('transfer_bonus_granted_at');
        });
    }
};

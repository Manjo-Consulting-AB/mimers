<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 50a (M9) · Registrerings-IP:t på kontot. Se [[ADR-0017
 * Missbruksvektorer]] § Konsekvenser ("Mätvärdena är personuppgiftsnära ...
 * De ska ha en gallringsfrist och stå i registerförteckningen") och
 * [[Registerförteckning]].
 *
 * Kolumnen fångar den IP en registrering kom in från, så att den nattliga
 * missbruksrapporten (50b) kan lista konton per registrerings-IP och
 * containers skapade i kluster från samma IP — det enda mätvärdet i
 * ADR-0017 som inte redan fanns i en befintlig tabell. Den skrivs på exakt
 * ett ställe, App\Actions\Auth\CreatesUserWithPersonalAccount, i samma
 * transaktion som kontot skapas (Beslut 3 och 5).
 *
 * VARCHAR(45) är en IPv6-adress i textform — samma bredd som Laravels egen
 * sessions.ip_address. Nullbar av tre skäl (Beslut 1): befintliga konton
 * har ingen, ett gallrat konto har ingen längre, och en request utan
 * pålitlig IP ska inte hindra en registrering. Ingen backfill (Beslut 6):
 * underlaget finns inte, och en gissning ur sessions vore påhittad data —
 * de kontona behåller NULL för alltid.
 *
 * Ingen ulid, inget index (Beslut 1): kolumnen läses av ett nattligt jobb
 * som ändå går igenom hela account, och ett index på en personuppgift som
 * ska bort om nittio dagar är underhåll utan nytta. Gallringsfristen räknas
 * ur account.created_at, som redan finns och aldrig ändras — därför ingen
 * egen tidsstämpelkolumn (Beslut 8).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->string('registration_ip', 45)->nullable()->after('read_only_reason');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('account', function (Blueprint $table) {
            $table->dropColumn('registration_ip');
        });
    }
};

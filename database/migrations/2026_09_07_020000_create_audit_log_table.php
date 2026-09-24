<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 40 · Revisionsloggen. Se [[Konton och åtkomst]] § audit_log och
 * [[Konkurrens]] § punkt 4. Tabellen är systemets anteckning om vad som hänt
 * i en pärm — den enda vägen in är App\Actions\Audit\RecordAuditEvent, och
 * varje känslig händelse (ägarbyten, återkallade åtkomster, och fler som
 * senare issues kopplar på) skriver en rad i anroparens transaktion.
 *
 * Tre avvikelser från AGENTS.md § Databaskonventioner är medvetna och är
 * hela poängen med tabellen (issue 40 § Beslut 3):
 *
 * - Ingen `updated_at`: en revisionslogg som kan ändras är inget bevis.
 *   Modellen sätter `const UPDATED_AT = null`.
 * - Ingen `deleted_at`: en logg som kan mjukraderas är sämre än ingen alls.
 *   Rader mjukraderas aldrig och ändras aldrig, men de gallras:
 *   App\Console\PrunesLogs (issue 115) tar bort en containers rader tolv
 *   månader efter dess `container.purged`-rad, och containerlösa rader tolv
 *   månader efter kontots `account.deleted` ([[ADR-0043 Tre loggar]]
 *   § Händelseloggen).
 * - Ingen ändring, ingen radering: det finns ingen rutt som skriver om en
 *   rad. Append-only.
 *
 * `subject_type` är ett domännamn, inte ett klassnamn (Beslut 4):
 * `'container'`, `'container_access'`, `'ownership_transfer'` — samma stil
 * som `container_access.grantee_type`. Inget `morphTo()` och ingen
 * främmandenyckel: `subject_id` bär subjektets ULID (Beslut 5) och skrivs
 * och läses bara som identifierare, aldrig som join.
 *
 * `action` får INGET CHECK-villkor (Beslut 6): `container.transferred` och
 * `access.revoked` är de två första värdena, men namnrummet är öppet med
 * flit — senare issues lägger fler händelser genom samma action utan att
 * migrera. Värdena bor som konstanter på App\Models\AuditLog, så anroparna
 * aldrig stavar en sträng.
 *
 * `meta` är JSON och NOT NULL. Tomt serialiseras som `{}`, aldrig `[]`, av
 * App\Http\Resources\AuditLogResource — samma regel som felformatets
 * `data`, se AGENTS.md § Felformat.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_log', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('account_id')->nullable()->constrained('account')->onDelete('restrict');
            $table->foreignId('user_id')->nullable()->constrained('user')->onDelete('restrict');
            $table->foreignId('container_id')->nullable()->constrained('container')->onDelete('restrict');
            $table->string('action', 60);
            $table->string('subject_type', 40)->nullable();
            $table->char('subject_id', 26)->nullable();
            $table->json('meta');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['container_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};

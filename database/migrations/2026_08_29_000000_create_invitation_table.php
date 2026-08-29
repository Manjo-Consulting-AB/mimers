<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 10a · Inbjudningar, första halvan (avsändarytan). Se
 * [[Konton och åtkomst]] § invitation och [[ADR-0003 Åtkomstmodell]].
 *
 * Delning med någon som ännu inte har konto: raden ligger `pending` tills
 * den accepteras, avvisas, dras tillbaka eller löper ut. Mottagarsidan —
 * mejlet, accept och avvisning — är issue 10b och rör inte den här
 * tabellen mer än genom `token_hash`-uppslaget.
 *
 * `token_hash` är en SHA-256-hex av den slump som skickas i mejlets länk,
 * aldrig slumpen själv — därav exakt CHAR(64), se issue 10a § Beslut 5 och
 * förlagan App\Support\Auth\MagicLinkBroker.
 *
 * Inget `deleted_at`, till skillnad från AGENTS.md § Databaskonventioner:
 * en inbjudan dras tillbaka med `status = 'revoked'`, den mjukraderas
 * inte — raden är historik. Utestående och tillbakadragna inbjudningar är
 * underlaget för [[ADR-0017 Missbruksvektorer]] och M9, se
 * [[Konton och åtkomst]] § invitation och issue 10a § Beslut 2. Samma
 * avvikelse, av samma skäl, som `container_access` gjorde i 9a.
 *
 * `expires_at` är NOT NULL — en inbjudan går alltid ut (14 dagar, se
 * App\Models\Invitation::TTL_DAYS). Till skillnad från
 * `container_access.expires_at` finns här inget "går aldrig ut".
 * Kolumnen flippas ALDRIG av ett städjobb: `status` står kvar på
 * `pending` när tiden passerat och utgången härleds i kod
 * (App\Models\Invitation::isExpired()), samma princip som 9a § Beslut 7.
 *
 * Index, se issue 10a § Beslut 4 — dokumentet listar inga:
 * - `token_hash` UNIKT: 10b:s enda uppslagsväg vid accept, och unikheten
 *   gör en hashkollision till ett skrivfel i stället för en tyst
 *   förväxling av två inbjudningar.
 * - `(container_id, status)`: listningen och duplikatspärren i
 *   App\Http\Controllers\Api\ContainerInvitationController.
 * - `(email, status)`: 10b slår upp mottagarens öppna inbjudningar på
 *   adressen, och M9 räknar utestående inbjudningar per adress.
 *
 * CHECK-villkoren läggs bara på mysql; sqlite (test) saknar stöd för
 * ALTER TABLE ... ADD CONSTRAINT. Se förlagan
 * 2026_08_26_000000_create_container_access_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invitation', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->string('email', 255);
            $table->string('level', 20);
            $table->char('token_hash', 64)->unique();
            $table->string('status', 20);
            $table->timestamp('expires_at');
            $table->foreignId('invited_by_user_id')->constrained('user')->onDelete('restrict');
            $table->timestamps();

            $table->index(['container_id', 'status']);
            $table->index(['email', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE invitation ADD CONSTRAINT invitation_level_check CHECK (level IN ('read', 'write'))");
            DB::statement("ALTER TABLE invitation ADD CONSTRAINT invitation_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'expired', 'revoked'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invitation');
    }
};

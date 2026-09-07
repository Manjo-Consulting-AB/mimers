<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 39a · Ägarbyte, första halvan (initieringsytan). Se
 * [[Konton och åtkomst]] § ownership_transfer och [[ADR-0003 Åtkomstmodell]].
 *
 * Ägarbyte täcker nybyggnadsvarv → kund, mäklare → köpare och privat
 * försäljning med samma mekanism. Den här issuen bygger bara tabellen,
 * modellen och initieringen — accepten (transaktionen som flyttar
 * `container.account_id`, förbrukningen, åtkomsterna och planen) är 39b och
 * rör ingen fil här.
 *
 * Inget `deleted_at`, till skillnad från AGENTS.md § Databaskonventioner:
 * raden är historik, precis som `invitation` och `container_access`. Ett
 * ägarbyte som ångras får `status = 'revoked'`, det mjukraderas inte (issue
 * 39a § Beslut 1).
 *
 * Tre kolumner är värda att stanna vid, se issue 39a § Beslut 2:
 * - `to_account_id` är nullable: exakt en av `to_account_id` och `to_email`
 *   är satt, aldrig båda, aldrig ingen (Beslut 4). CHECK-villkoret nedan
 *   speglar regeln så databasen inte kan bära ett tillstånd API:et förbjuder.
 * - `initiated_by_user_id` finns, som systertabellens `invited_by_user_id` —
 *   39b sätter den som `granted_by_user_id` på den kvarhållna åtkomsten.
 * - `status` tillåter `revoked`, avsändarens ånger, av samma skäl som på
 *   `invitation`: en överlåtelse skickad till fel adress måste gå att dra
 *   tillbaka. `expired` finns i villkoret men skrivs av ingen kod i MVP —
 *   utgången härleds i stället i kod, se App\Models\OwnershipTransfer.
 *
 * `excluded_item_ids` lagrar item-ULID:er, inte löpnummer (Beslut 3).
 * Kolumnnamnet kommer från dokumentet; innehållet är den identifierare
 * klienten skickar in och får tillbaka. En JSON-lista kan aldrig bära en
 * främmande nyckel, så det enda som skyddar den är valideringen i
 * StoreOwnershipTransferRequest — och en lista av löpnummer skulle dessutom
 * vara ett löpnummer på väg ut genom en resurs. Tom lista serialiseras som
 * `[]`, aldrig `null`; kolumnen är NOT NULL med `[]` som förval från
 * applikationen, inte från databasen.
 *
 * CHECK-villkoren läggs bara på mysql; sqlite (test) saknar stöd för
 * ALTER TABLE ... ADD CONSTRAINT. Se förlagan
 * 2026_08_29_000000_create_invitation_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('ownership_transfer', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->foreignId('from_account_id')->constrained('account')->onDelete('restrict');
            $table->foreignId('to_account_id')->nullable()->constrained('account')->onDelete('restrict');
            $table->string('to_email', 255)->nullable();
            $table->json('excluded_item_ids');
            $table->string('retain_access_level', 20)->nullable();
            $table->string('status', 20);
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('initiated_by_user_id')->constrained('user')->onDelete('restrict');
            $table->timestamps();

            $table->index(['container_id', 'status']);
            $table->index(['to_account_id', 'status']);
            $table->index(['to_email', 'status']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE ownership_transfer ADD CONSTRAINT ownership_transfer_status_check CHECK (status IN ('pending', 'accepted', 'rejected', 'expired', 'revoked'))");
            DB::statement('ALTER TABLE ownership_transfer ADD CONSTRAINT ownership_transfer_recipient_check CHECK ((to_account_id IS NOT NULL AND to_email IS NULL) OR (to_account_id IS NULL AND to_email IS NOT NULL))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ownership_transfer');
    }
};

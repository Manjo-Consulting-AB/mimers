<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 9a · Åtkomstmodell och behörighetspolicy, första halvan. Se
 * [[Konton och åtkomst]] § container_access och
 * [[ADR-0003 Åtkomstmodell]].
 *
 * De delegerade åtkomsterna till en container, utöver ägarskapet
 * (`container.account_id`). `kind` har bara TRE värden här — `member`,
 * `managed`, `guest` — ägarskap är INTE en rad i den här tabellen, se
 * issue 9a § Beslut 1: ADR:ns "fyra åtkomstformer" räknar in ägarskapet i
 * prosan, men datamodellen vinner.
 *
 * `grantee_id` (BIGINT UNSIGNED) pekar på `user.id` ELLER `account.id`
 * beroende på `grantee_type` och får medvetet INGEN främmandenyckel — en
 * kolumn kan inte peka mot två olika tabeller samtidigt, se issue 9a §
 * Beslut 3. Indexet `(grantee_type, grantee_id, revoked_at)` bär
 * uppslagningen i stället för en FK.
 *
 * Inget `deleted_at`, till skillnad från AGENTS.md § Databaskonventioner:
 * en åtkomst återkallas med `revoked_at`, inte mjukraderas — raden är
 * historik (att kunna visa att varvet hade åtkomst mellan mars och
 * november är hela poängen), se issue 9a § Beslut 4 och
 * [[ADR-0003 Åtkomstmodell]] § Motivering.
 *
 * `expires_at` NULL betyder "går aldrig ut", inte "gick ut för länge
 * sedan" — se issue 9a § Att se upp med.
 *
 * CHECK-villkoret läggs bara på mysql; sqlite (test) saknar stöd för
 * ALTER TABLE ... ADD CONSTRAINT. Se förlagan
 * 2026_08_25_010000_create_container_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('container_access', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->string('grantee_type', 20);
            $table->unsignedBigInteger('grantee_id');
            $table->string('level', 20);
            $table->string('kind', 20);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('granted_by_user_id')->constrained('user')->onDelete('restrict');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['container_id', 'revoked_at']);
            $table->index(['grantee_type', 'grantee_id', 'revoked_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE container_access ADD CONSTRAINT container_access_grantee_type_check CHECK (grantee_type IN ('user', 'account'))");
            DB::statement("ALTER TABLE container_access ADD CONSTRAINT container_access_level_check CHECK (level IN ('read', 'write'))");
            DB::statement("ALTER TABLE container_access ADD CONSTRAINT container_access_kind_check CHECK (kind IN ('member', 'managed', 'guest'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container_access');
    }
};

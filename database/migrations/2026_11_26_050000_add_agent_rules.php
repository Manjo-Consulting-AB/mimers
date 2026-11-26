<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 512 · M95 Agentregler och mallarna, [[ADR-0100 Agentregler]].
 *
 * Fritextregler till agenten i två fält: `user.agent_rules` följer
 * användarens agenter överallt, `container.agent_rules` gäller varje agent
 * som når containern. Tomt fält är NULL.
 *
 * `agent_rules_revision` sparar hela texten efter varje ändring, vem som
 * skrev den och när. Append-only: ingen `updated_at`, ingen `deleted_at`.
 * Exakt en av `user_id` och `container_id` är satt — CHECK-villkoret läggs
 * bara på mysql, samma drivrutinsgrening som andra migrationer. `user_id`
 * är CASCADE (raden följer personraderingen), `container_id` RESTRICT
 * (App\Actions\Trash\PurgeContainer tar revisionerna först) och
 * `author_user_id` SET NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user', function (Blueprint $table) {
            $table->text('agent_rules')->nullable();
        });

        Schema::table('container', function (Blueprint $table) {
            $table->text('agent_rules')->nullable();
        });

        Schema::create('agent_rules_revision', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('user_id')->nullable()->constrained('user')->onDelete('cascade');
            $table->foreignId('container_id')->nullable()->constrained('container')->onDelete('restrict');
            $table->foreignId('author_user_id')->nullable()->constrained('user')->onDelete('set null');
            $table->text('rules')->nullable();
            $table->unsignedInteger('revision');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'revision']);
            $table->unique(['container_id', 'revision']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE agent_rules_revision ADD CONSTRAINT agent_rules_revision_owner_check CHECK ((user_id IS NULL) <> (container_id IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_rules_revision');

        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn('agent_rules');
        });

        Schema::table('user', function (Blueprint $table) {
            $table->dropColumn('agent_rules');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 16a · attachment: kopplingen mellan ett item och de lagrade
 * bytena, med användarens eget filnamn. Se [[Filer och lagring]] §
 * attachment och [[ADR-0006 Innehållsadresserad lagring]].
 *
 * Två användare som laddar upp samma Victron-manual får två rader här och
 * en rad i stored_file; varje rad bär sitt eget `filename` (§ Beslut 11).
 *
 * `uploaded_by_user_id` kommer alltid från token (§ Beslut 14) och
 * `billed_account_id` från kroppens `account` efter medlemskapskontrollen
 * (§ Beslut 2) — kontot som betalar, inte containerns ägare.
 *
 * Index, se dokumentets kolumnlista: `(item_id, deleted_at)` för 16b:s
 * listning, `(billed_account_id, deleted_at)` för M4:s kvotavläsning,
 * `(stored_file_id)` för baklängesuppslaget. CHECK bakom mysql-guarden,
 * samma förlaga som invitation.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attachment', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('item_id')->constrained('item')->onDelete('restrict');
            $table->foreignId('stored_file_id')->constrained('stored_file')->onDelete('restrict');
            $table->string('filename', 255);
            $table->string('kind', 20);
            $table->foreignId('uploaded_by_user_id')->constrained('user')->onDelete('restrict');
            $table->foreignId('billed_account_id')->constrained('account')->onDelete('restrict');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['item_id', 'deleted_at']);
            $table->index(['billed_account_id', 'deleted_at']);
            $table->index(['stored_file_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attachment ADD CONSTRAINT attachment_kind_check CHECK (kind IN ('image', 'document', 'other'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attachment');
    }
};

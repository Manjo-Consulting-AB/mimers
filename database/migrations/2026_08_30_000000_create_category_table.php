<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 11 · Kategorier: hierarki, djup och cykelkontroll. Se
 * [[Items och organisation]] § category och
 * [[ADR-0004 Fria taggar och kategorier]].
 *
 * `category` är en hierarki per container — ett item tillhör högst en
 * kategori, se ADR-0004. `parent_id` (FK → category, NULL, ON DELETE
 * RESTRICT) bär hierarkin; roten har `parent_id = null`. Djupgränsen (fem
 * nivåer) och cykelkontrollen bor i applikationslagret
 * (App\Actions\Category\MoveCategory), inte här — se issue 11 § Beslut 4
 * och 8.
 *
 * Ingen `depth`, ingen `path`, ingen `item_count` — ett lagrat djup kan
 * hamna i otakt med trädet, se issue 11 § Beslut 3.
 *
 * `position` är signerad INT, ingen `unsigned()` — se issue 11 § Att se
 * upp med: dokumentet ställer inget sådant krav.
 *
 * `deleted_at`: kategorin är användarskapat innehåll och mjukraderas.
 * Index `(container_id, parent_id, deleted_at)` täcker både trädfrågan
 * (issue 11 § Beslut 8: hela containerns träd i en fråga) och
 * listningens `deleted_at`-filter, se AGENTS.md § Databaskonventioner.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('category', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->foreignId('parent_id')->nullable()->constrained('category')->onDelete('restrict');
            $table->string('name');
            $table->integer('position');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['container_id', 'parent_id', 'deleted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('category');
    }
};

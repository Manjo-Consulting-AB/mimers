<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Exempeltabell för issue 2 · Gemensamma modellkonventioner.
 *
 * Bevisar konventionerna i AGENTS.md § Databaskonventioner: löpnummer som
 * primärnyckel, ulid som extern identifierare, tidsstämplar i UTC och mjuk
 * radering med deleted_at i listningsindexet. Ingen domäntabell — den
 * ersätts inte av något i issue 3, den tas bara inte med som förlaga.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('examples', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['deleted_at', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('examples');
    }
};

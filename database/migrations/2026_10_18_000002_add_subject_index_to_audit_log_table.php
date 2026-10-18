<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * M39 · issue 318. Se [[ADR-0065 Den samlade papperskorgen]] § 5.
 *
 * `ListAllTrash` läser vem som raderade en rad ur händelseloggen: den
 * senaste `item.deleted`, `attachment.deleted` eller `container.deleted`
 * för radens `subject_type` och `subject_id`. Utan ett index på de tre
 * kolumnerna hade uppslaget blivit en genomsökning av hela `audit_log` för
 * varje sida i papperskorgen.
 *
 * Indexet är additivt: det ändrar ingen rad och ingen fråga som redan
 * fungerar. Loggens befintliga index — `(container_id, created_at)`,
 * `(item_id, created_at)`, `(user_id, created_at)` och `(created_at)` — har
 * alla en annan ledande kolumn och kan inte användas för det här uppslaget.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id', 'created_at']);
        });
    }
};

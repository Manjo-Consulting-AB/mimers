<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 16a · stored_file: bytena, innehållsadresserade. Se
 * [[Filer och lagring]] § stored_file och [[ADR-0006 Innehållsadresserad
 * lagring]].
 *
 * En rad per unikt innehåll i hela systemet. `content_hash` är SHA-256-hex,
 * alltid beräknad på servern (issue 16a § Beslut 3); UNIQUE-indexet är det
 * som gör dedupen säker — två samtidiga uppladdningar av samma byten kan
 * kollidera på det innan låset tas, och actionen fångar brottet och läser
 * om raden i stället för att skapa en andra rad (§ Beslut 10).
 *
 * Ingen `ulid` — tabellen syns aldrig i API:et (§ Beslut 13). Ingen
 * `deleted_at` — en stored_file lever exakt så länge någon refererar den;
 * när `reference_count` når noll raderas raden och bytena, men först efter
 * en fördröjning, se issue 17.
 *
 * `scan_status` är alltid `'skipped'` i MVP (§ Beslut 8); CHECK-villkoret
 * finns i schemat från start. CHECK läggs bara på mysql, se förlagan
 * 2026_08_29_000000_create_invitation_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('stored_file', function (Blueprint $table) {
            $table->id();
            $table->char('content_hash', 64)->unique();
            $table->unsignedBigInteger('byte_size');
            $table->string('mime_type', 127);
            $table->string('storage_path', 255);
            $table->unsignedInteger('reference_count');
            $table->string('scan_status', 20);
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE stored_file ADD CONSTRAINT stored_file_scan_status_check CHECK (scan_status IN ('pending', 'clean', 'infected', 'skipped'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stored_file');
    }
};

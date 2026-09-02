<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 17a · Markeringen för gallring på stored_file. Se [[Filer och
 * lagring]] § Radering och [[ADR-0008 Soft delete och papperskorg]] §
 * Retentionstiden i MVP.
 *
 * `purge_after` är null så länge någon refererar bytena och sätts till
 * now() + 30 dagar i samma ögonblick som `reference_count` når noll — av
 * App\Actions\Attachment\PurgeAttachment. Kolumnen ÄR markeringen: den
 * fysiska raderingen som läser den är issue 17b, som också tar bort raden
 * här. Den här migrationen tar aldrig bort en fil från disken.
 *
 * Alternativet att räkna fram tiden ur `updated_at` valdes bort (Beslut 1):
 * updated_at rörs av varje ökning av räknaren och skulle göra fördröjningen
 * till en slump.
 *
 * Indexet på `purge_after` är det enda 17b frågar på — gallringsjobbet
 * letar rader vars markering har passerats och ingenting annat.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stored_file', function (Blueprint $table) {
            $table->timestamp('purge_after')->nullable()->after('scan_status');
            $table->index('purge_after');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stored_file', function (Blueprint $table) {
            $table->dropIndex(['purge_after']);
            $table->dropColumn('purge_after');
        });
    }
};

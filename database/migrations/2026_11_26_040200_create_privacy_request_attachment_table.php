<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 486 · M87 Integritetsbegäranden. Se [[ADR-0095 Integritetsbegäranden]]
 * § 10 och [[Konton och åtkomst]] § privacy_request_attachment.
 *
 * En fil på ärendet: svaret som skickades, en sammanställning eller en
 * fullmakt. Raden bär bara det som behövs för att hitta och beskriva filen —
 * filnamnet när den lades till, storleken och SHA-256 räknad på servern.
 * Själva filen ligger på disken `privacy` under `{ärendets ulid}/{bilagans
 * ulid}`, utan filändelse (config/filesystems.php).
 *
 * **Ingen koppling till den innehållsadresserade lagringen.** Det finns
 * ingen `stored_file_id`: bilagan dedupliceras inte, räknas inte mot någon
 * kvot och nås aldrig av PurgesExpiredStoredFiles. Disken är egen av samma
 * skäl (ADR-0006 Innehållsadresserad lagring gäller inte här).
 *
 * **`ulid` är identifieraren utåt** — kommandona `privacy:detach` och
 * `privacy:show` talar om bilagan med den, och sökvägen på disken slutar
 * med den. Den unika indexeringen är skyddet mot två rader med samma fil.
 *
 * `privacy_request_id` är en riktig främmande nyckel med RESTRICT: bilagan
 * existerar bara som en del av sitt ärende. Gallringen (issue 487) tar
 * bilagorna och filerna före ärendet, precis som den tar händelserna.
 *
 * `sha256` är CHAR(64) och inte text: hashen har alltid samma längd, och
 * kolumnen är ett värde att jämföra, inte fritext. Den räknas av
 * App\Actions\Privacy\AttachPrivacyAttachment och aldrig från en klient.
 *
 * Ingen `deleted_at`: en bilaga tas bort på riktigt med `privacy:detach`,
 * och raden försvinner med ärendet.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('privacy_request_attachment', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('privacy_request_id')->constrained('privacy_request')->onDelete('restrict');
            $table->string('original_name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('added_by', 120);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_request_attachment');
    }
};

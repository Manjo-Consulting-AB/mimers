<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 481 · M87 Integritetsbegäranden. Se [[ADR-0095 Integritetsbegäranden]]
 * § 3 och § 7 samt [[Konton och åtkomst]] § privacy_request_event.
 *
 * Ärendets händelser: allt som hänt med det, i tidsordning. `created` skrivs
 * av App\Actions\Privacy\OpenPrivacyRequest, `viewed` av `privacy:show`, och
 * senare issuers skriver sina egna slag — statusbyten, verifiering,
 * bedömning och begränsning.
 *
 * **Append-only.** Ingen `updated_at`, ingen `deleted_at`: en händelse som
 * kan ändras är inget bevis. Den enda vägen in är
 * App\Actions\Privacy\RecordPrivacyEvent, och `const UPDATED_AT = null`
 * stänger av Eloquents andra tidsstämpel.
 *
 * **Ingen `ulid`** — ingen rutt, ingen resurs och ingen vy identifierar en
 * händelse (AGENTS.md § Databaskonventioner: kravet gäller tabeller som syns
 * i API:et, och den här har ingen yta).
 *
 * `privacy_request_id` är en riktig främmande nyckel med RESTRICT: en
 * händelse existerar bara som en del av sitt ärende, och ärendet raderas
 * aldrig medan en händelse pekar på det — gallringen (issue 487) tar
 * händelserna först. Det skiljer tabellen från `legal_hold`, vars rad ska
 * överleva sitt subjekt: en händelse utan ärende är inte ett bevis, den är
 * en föräldralös rad.
 *
 * `actor` är handläggarens namn ur kommandots `--by` (ADR-0095 § 1), `body`
 * är fritext — ett skäl eller en anteckning — och `data` är JSON med det som
 * hör till slaget. Värdena i `data` kommer ur listorna i config/privacy.php.
 *
 * Indexet `(privacy_request_id, created_at)` är läsvägen: `privacy:show`
 * skriver ut händelserna i tidsordning. Indexet börjar med nyckelkolumnen,
 * så det täcker även den främmande nyckeln.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('privacy_request_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('privacy_request_id')->constrained('privacy_request')->onDelete('restrict');
            $table->string('kind', 30);
            $table->string('actor', 120);
            $table->text('body')->nullable();
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['privacy_request_id', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE privacy_request_event ADD CONSTRAINT privacy_request_event_kind_check CHECK (kind IN ('created', 'viewed', 'status_changed', 'assigned', 'verification', 'linked', 'assessment', 'action', 'communication', 'deadline_changed', 'note', 'attachment_added', 'attachment_removed', 'restriction_placed', 'restriction_lifted'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_request_event');
    }
};

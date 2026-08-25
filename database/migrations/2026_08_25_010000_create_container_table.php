<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 8 · Container. Se [[Konton och åtkomst]] § container och
 * [[ADR-0002 Konto äger container]].
 *
 * `container` är det ägda objektet — båten, husvagnen, huset. Ägs av
 * exakt ett konto (`account_id`, ON DELETE RESTRICT — ett konto med
 * containers kvar får inte raderas under dem). `kind` är VARCHAR med
 * CHECK-villkor, aldrig ENUM, se AGENTS.md § Databaskonventioner, och styr
 * bara presentation — systemet beter sig aldrig olika beroende på värdet.
 *
 * `template_source_id` (FK → container, NULL, ON DELETE RESTRICT) skapas
 * här men används inte förrän mallar byggs — den sätts aldrig via API:et i
 * den här issuen, se issue 8 § Att se upp med.
 *
 * `deleted_at`: containern är användarskapat innehåll och mjukraderas, till
 * skillnad från `account` som saknar den kolumnen. Index
 * `(account_id, deleted_at)` för listningen i issue 8 § Beslut 6.
 *
 * CHECK-villkoret läggs bara på mysql; sqlite (test) saknar stöd för
 * ALTER TABLE ... ADD CONSTRAINT. Se förlagan
 * 2026_08_24_115142_create_account_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('container', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('account_id')->constrained('account')->onDelete('restrict');
            $table->string('name');
            $table->string('kind', 40);
            $table->foreignId('template_source_id')->nullable()->constrained('container')->onDelete('restrict');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['account_id', 'deleted_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE container ADD CONSTRAINT container_kind_check CHECK (kind IN ('boat', 'caravan', 'house', 'car', 'other'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('container');
    }
};

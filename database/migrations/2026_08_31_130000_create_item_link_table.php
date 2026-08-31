<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 14 · Relationer mellan items. Se [[Items och organisation]] §
 * item_link och [[ADR-0024 Tunna controllers och actions]].
 *
 * En relation mellan två items lagras exakt en gång och motsatsen härleds
 * vid läsning (issue 14 § Beslut 4): `parent` skrivs alltid, `child` härleds,
 * `sibling` normaliseras till lägst `id` först. Kolumnerna är dokumentets —
 * `id`, `from_item_id`, `to_item_id`, `relation`, `created_at`, `updated_at` —
 * plus de två index som läsningen kräver (§ Beslut 3).
 *
 * Ingen `ulid` (§ Beslut 1): ett par av items har högst en relation, så
 * motpartens ULID identifierar länken, och det finns ingen rutt som
 * identifierar en enskild rad. Ingen `deleted_at` (§ Beslut 10): raderingen
 * är hård, och en mjukraderad ände döljer bara länken vid läsning — raden
 * ligger kvar så att en återupplivning (issue 20) tar tillbaka relationen.
 * Ingen `container_id`: containern härleds ur itemen, som aldrig byter
 * container (§ Beslut 3).
 *
 * Index, se issue 14 § Beslut 3:
 * - UNIQUE `(from_item_id, to_item_id, relation)`: dokumentets — "relationen
 *   lagras en gång". Utan den kan samma par och relation skrivas två gånger
 *   (normaliseringen i § Beslut 4 ska hindra det, men unikheten gör ett fel
 *   till ett skrivfel i stället för en tyst dubblett).
 * - `(to_item_id, from_item_id)`: läsningen "vilka länkar pekar på det här
 *   itemet" — varje läsning behöver båda riktningarna (§ Beslut 8), och utan
 *   det omvända indexet blir den en full scan. Samma resonemang som det
 *   omvända indexet på `item_tag` (issue 13b § Beslut 1).
 *
 * CHECK-villkoret läggs bara på mysql; sqlite (test) saknar stöd för
 * ALTER TABLE ... ADD CONSTRAINT. Se förlagan
 * 2026_08_29_000000_create_invitation_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('item_link', function (Blueprint $table) {
            $table->id();
            $table->foreignId('from_item_id')->constrained('item')->onDelete('restrict');
            $table->foreignId('to_item_id')->constrained('item')->onDelete('restrict');
            $table->string('relation', 20);
            $table->timestamps();

            $table->unique(['from_item_id', 'to_item_id', 'relation']);
            $table->index(['to_item_id', 'from_item_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE item_link ADD CONSTRAINT item_link_relation_check CHECK (relation IN ('parent', 'child', 'sibling'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item_link');
    }
};

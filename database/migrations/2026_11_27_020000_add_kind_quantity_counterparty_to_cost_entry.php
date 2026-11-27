<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 1778 · M99 Transaktioner på items, se [[ADR-0102 Transaktioner på
 * items]] och [[Items och organisation]] § cost_entry.
 *
 * En kostnadsrad får en riktning (`kind`: `expense` eller `income`), ett
 * frivilligt antal (`quantity`) och en motpart i stället för en leverantör
 * (`supplier` döps om till `counterparty`). Befintliga rader blir `expense`
 * genom kolumnens default. Tabellen och modellen behåller sina namn —
 * namnbytet är issue 532.
 *
 * Indexet `(container_id, deleted_at, supplier)` ersätts av
 * `(container_id, deleted_at, kind, counterparty)`. CHECK-villkoret läggs bara
 * på mysql/mariadb, samma drivrutinsgrening som add_agent_rules. `down()` gör
 * allt baklänges.
 */
return new class extends Migration
{
    private function supportsCheck(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function up(): void
    {
        Schema::table('cost_entry', function (Blueprint $table) {
            $table->dropIndex(['container_id', 'deleted_at', 'supplier']);
        });

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->renameColumn('supplier', 'counterparty');
            $table->string('kind', 16)->default('expense')->after('item_id');
            $table->decimal('quantity', 18, 6)->nullable()->after('amount');
        });

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->index(['container_id', 'deleted_at', 'kind', 'counterparty']);
        });

        if ($this->supportsCheck()) {
            DB::statement("ALTER TABLE cost_entry ADD CONSTRAINT cost_entry_kind_check CHECK (kind IN ('expense','income'))");
        }
    }

    public function down(): void
    {
        if ($this->supportsCheck()) {
            DB::statement('ALTER TABLE cost_entry DROP CONSTRAINT cost_entry_kind_check');
        }

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->dropIndex(['container_id', 'deleted_at', 'kind', 'counterparty']);
        });

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->dropColumn(['kind', 'quantity']);
            $table->renameColumn('counterparty', 'supplier');
        });

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->index(['container_id', 'deleted_at', 'supplier']);
        });
    }
};

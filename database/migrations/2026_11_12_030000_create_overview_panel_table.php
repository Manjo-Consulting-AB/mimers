<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Personens ordning för panelerna på containerns översikt, se
 * [[ADR-0073 Översiktens ordning]] och [[Konton och åtkomst]] §
 * overview_panel. En rad per panel och person; ingen `ulid` och ingen
 * `deleted_at`. CHECK-villkoret läggs bara på mysql, som i övriga
 * migrationer (sqlite i test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('overview_panel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('user')->onDelete('restrict');
            $table->string('panel_key', 40);
            $table->unsignedSmallInteger('position');
            $table->string('width', 10);
            $table->timestamps();

            $table->unique(['user_id', 'panel_key']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE overview_panel ADD CONSTRAINT overview_panel_width_check CHECK (width IN ('narrow', 'wide'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('overview_panel');
    }
};

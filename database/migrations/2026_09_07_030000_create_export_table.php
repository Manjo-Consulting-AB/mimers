<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 41 · Export — en beställd fullständig export av en container
 * (metadata + filer), se [[Backlog]] M6 § 41 och [[ADR-0014 Prismodell]]
 * (export är fri på alla nivåer). Tabellen är beställningens rad: status,
 * var artefakten hamnade och när den gallras.
 *
 * `status` är en sluten mängd — `pending` | `running` | `ready` | `failed`
 * | `expired` — och får sitt CHECK-villkor på mysql, precis som
 * webhook_delivery. `expired` skrivs av gallringen (41b), inte av den här
 * issuen.
 *
 * `ulid` är identifieraren utåt: klienten pollar exporten genom den, och
 * sökvägen på disken byggs av container- och export-ULID (Beslut 6). Inget
 * `deleted_at` — raden är historik över en beställning, precis som
 * webhook_delivery.
 *
 * `container_id` och `requested_by_user_id` är ON DELETE RESTRICT: en rad
 * här är en beställning någon gjort, och den ska inte tyst försvinna om en
 * container eller användare försvinner under den.
 *
 * `storage_path`, `byte_size`, `failure_reason` och `expires_at` är null
 * tills jobbet har skrivit klart (eller misslyckats): en `pending`-rad
 * berättar inget om utfallet.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('export', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->foreignId('requested_by_user_id')->constrained('user')->onDelete('restrict');
            $table->string('status', 20);
            $table->string('storage_path', 255)->nullable();
            $table->unsignedBigInteger('byte_size')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['container_id', 'created_at']);
            $table->index(['status', 'expires_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE export ADD CONSTRAINT export_status_check CHECK (status IN ('pending', 'running', 'ready', 'failed', 'expired'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('export');
    }
};

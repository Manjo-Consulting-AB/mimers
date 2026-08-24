<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 3 · Konto och användare. Se [[Konton och åtkomst]] § account.
 *
 * `account` är ägarenheten — ett privatkonto är bara ett konto med en enda
 * medlem, se [[ADR-0002 Konto äger container]]. Inget deleted_at: kontots
 * livscykel går via `status`, inte soft delete, se AGENTS.md-avvikelsen som
 * issue 3 pekar ut. Uppräkningarna (`type`, `status`, `read_only_reason`) är
 * VARCHAR med CHECK-villkor, aldrig MySQL ENUM — se AGENTS.md
 * § Databaskonventioner. CHECK-villkoren läggs bara på mysql; sqlite (test)
 * saknar stöd för ALTER TABLE ... ADD CONSTRAINT.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('account', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('type', 20);
            $table->string('name');
            $table->string('locale', 10);
            $table->string('timezone', 64);
            $table->string('unit_system', 10);
            $table->string('status', 20);
            $table->string('read_only_reason', 40)->nullable();
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE account ADD CONSTRAINT account_type_check CHECK (type IN ('personal', 'organisation'))");
            DB::statement("ALTER TABLE account ADD CONSTRAINT account_unit_system_check CHECK (unit_system IN ('metric', 'imperial'))");
            DB::statement("ALTER TABLE account ADD CONSTRAINT account_status_check CHECK (status IN ('active', 'read_only', 'closed'))");
            DB::statement("ALTER TABLE account ADD CONSTRAINT account_read_only_reason_check CHECK (read_only_reason IS NULL OR read_only_reason IN ('payment_failed', 'over_quota', 'inactivity'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('account');
    }
};

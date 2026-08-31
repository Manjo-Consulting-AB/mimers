<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 13a · Item: the fundamental unit of the product. See
 * [[Items och organisation]] § item and [[ADR-0004 Fria taggar och
 * kategorier]].
 *
 * The columns are exactly the data model's, in that order — no extra
 * column, no `status`, no `quantity`, no `location_id` (a position is a
 * tag or a category, never its own entity, see ADR-0004). M2 (files), M3
 * (tasks), M6 (loans) and M8 (costs) all hang off this table, so the
 * column list is binding.
 *
 * `category_id` is nullable and holds at most one category (ADR-0004).
 * `created_by_user_id` is who created the row; `created_by_account_id` is
 * the account the row is attributed to — the yard, not the employee, see
 * [[Konton och åtkomst]] § Behörighetsregler rule 5.
 *
 * `purchased_at` and `warranty_until` are DATE, not timestamps — a
 * purchase date has no timezone, see issue 13a § Beslut 5.
 *
 * Indexes, see issue 13a § Beslut 4. The FULLTEXT index on
 * (name, description, manufacturer, model, serial_number) is used first
 * by issue 15b (search) but is created here because the document lists it
 * among the item's indexes, and adding it later to a full table costs
 * more. sqlite (the test suite) has no FULLTEXT equivalent, so it is
 * guarded behind the same driver check as the CHECK constraints in
 * `container` and `invitation`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('item', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->foreignId('category_id')->nullable()->constrained('category')->onDelete('restrict');
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('manufacturer', 255)->nullable();
            $table->string('model', 255)->nullable();
            $table->string('serial_number', 255)->nullable();
            $table->date('purchased_at')->nullable();
            $table->date('warranty_until')->nullable();
            $table->string('position_note', 255)->nullable();
            $table->foreignId('created_by_user_id')->constrained('user')->onDelete('restrict');
            $table->foreignId('created_by_account_id')->constrained('account')->onDelete('restrict');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['container_id', 'deleted_at']);
            $table->index(['container_id', 'category_id', 'deleted_at']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText(['name', 'description', 'manufacturer', 'model', 'serial_number']);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('item');
    }
};

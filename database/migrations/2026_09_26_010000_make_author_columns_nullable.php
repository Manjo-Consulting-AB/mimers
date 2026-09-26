<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 142 · Författarkolumnerna blir nullbara. Se
 * [[ADR-0045 Radering av konto och person]] § Beslut 2 och
 * [[Datamodell – översikt]].
 *
 * Kolumnerna som säger VEM som skapat, laddat upp, bockat av eller bjudit in
 * får NULL. Historiken står kvar, utan avsändare: raden raderas inte, den
 * förlorar sitt upphov. Det är förutsättningen för att kontoraderingen (143)
 * och personraderingen (144) ska kunna nollställa dem i stället för att
 * fastna på RESTRICT-nyckeln.
 *
 * FRÄMMANDE NYCKLARNA RÖRS INTE. De förblir ON DELETE RESTRICT. `nullable()`
 * är en ändring av kolumnen, inte av nyckeln, och en `SET NULL`-nyckel hade
 * varit en radering databasen hittar på själv — ingen action har bett om den
 * (ADR-0045 § Beslut 2, sista stycket).
 *
 * ADDITIV I EXPAND/CONTRACT-MENING, och den ändrar ingen befintlig rad:
 * `MODIFY COLUMN ... NULL` på MariaDB är en metadataändring, och ingen rad
 * skrivs av den här filen. Att göra en NOT NULL-kolumn nullbar är en
 * vidgning — varje värde som fick plats förut får plats nu.
 *
 * `down()` sätter tillbaka NOT NULL och är därför förlustbringande så fort en
 * rad har nollställts: den faller på MariaDB om någon rad står på NULL. Den
 * finns för testsviten — migrationer rullas aldrig tillbaka i produktion
 * (AGENTS.md § Databaskonventioner).
 *
 * `schedule_occurrence.completed_by_user_id` och `completed_by_account_id`
 * är redan nullbara sedan 2026_09_03_000000 och rörs inte här. Det är samma
 * tabell som ADR:ens tabell räknar upp dem i, och det är därför listan i
 * issuen inte har med dem.
 *
 * `foreignId()` och inte `unsignedBigInteger()`: kolumnen ska heta samma sak
 * och ha samma typ som förlagan i create-migreringen, och `foreignId()` är
 * repots stavning för en BIGINT UNSIGNED som pekar på en främmande tabell —
 * se 2026_08_31_000000_create_item_table.php. Ingen `constrained()` här:
 * nyckeln finns redan, och att deklarera den igen vore att lägga en andra.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->change();
            $table->foreignId('created_by_account_id')->nullable()->change();
        });

        Schema::table('attachment', function (Blueprint $table) {
            $table->foreignId('uploaded_by_user_id')->nullable()->change();
        });

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable()->change();
            $table->foreignId('created_by_account_id')->nullable()->change();
        });

        Schema::table('container_access', function (Blueprint $table) {
            $table->foreignId('granted_by_user_id')->nullable()->change();
        });

        Schema::table('invitation', function (Blueprint $table) {
            $table->foreignId('invited_by_user_id')->nullable()->change();
        });

        Schema::table('ownership_transfer', function (Blueprint $table) {
            $table->foreignId('initiated_by_user_id')->nullable()->change();
        });

        Schema::table('export', function (Blueprint $table) {
            $table->foreignId('requested_by_user_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('item', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable(false)->change();
            $table->foreignId('created_by_account_id')->nullable(false)->change();
        });

        Schema::table('attachment', function (Blueprint $table) {
            $table->foreignId('uploaded_by_user_id')->nullable(false)->change();
        });

        Schema::table('cost_entry', function (Blueprint $table) {
            $table->foreignId('created_by_user_id')->nullable(false)->change();
            $table->foreignId('created_by_account_id')->nullable(false)->change();
        });

        Schema::table('container_access', function (Blueprint $table) {
            $table->foreignId('granted_by_user_id')->nullable(false)->change();
        });

        Schema::table('invitation', function (Blueprint $table) {
            $table->foreignId('invited_by_user_id')->nullable(false)->change();
        });

        Schema::table('ownership_transfer', function (Blueprint $table) {
            $table->foreignId('initiated_by_user_id')->nullable(false)->change();
        });

        Schema::table('export', function (Blueprint $table) {
            $table->foreignId('requested_by_user_id')->nullable(false)->change();
        });
    }
};

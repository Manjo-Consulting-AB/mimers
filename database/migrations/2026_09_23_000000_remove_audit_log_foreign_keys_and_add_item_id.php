<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 107 · Händelseloggen överlever det den handlar om. Se
 * [[ADR-0043 Tre loggar]] § Händelseloggen och [[Konton och åtkomst]]
 * § audit_log.
 *
 * `account_id`, `user_id` och `container_id` släpper sina främmande nycklar
 * och blir identifierare, samma sak som `subject_id` redan är: kolumnerna
 * finns kvar och bär samma värden, men ingenting hindrar längre att raden
 * lever vidare efter det den beskriver. Loggen är historik — en gallrad
 * container ska kunna läsas om i tolv månader, och en loggrad får aldrig
 * fälla den nattliga gallringen.
 *
 * Bakgrunden är konkret: `PurgeContainer` tar hårt bort en container efter
 * trettio dagar i papperskorgen men rensar inte loggen, och ska inte göra
 * det. Med ON DELETE RESTRICT fällde varje container med en loggrad
 * gallringen varje natt. I dag är det sällsynt — loggen skrivs bara av
 * ägarbytet och återkallningen — men när M18 loggar varje handling blir det
 * varje container.
 *
 * Bara nycklarna släpps. Indexen MariaDB skapade åt dem står kvar; de gör
 * ingen skada och att släppa dem hade varit en andra, onödig ändring av
 * tabellen.
 *
 * `item_id` tillkommer, nullbar och UTAN främmande nyckel — samma skäl:
 * raden ska överleva itemet. Den sätts av RecordAuditEvent på varje händelse
 * som hör till ett item, oavsett subjekt, och gör att en rad kan läsas upp
 * per item utan att gå över containern. Indexen `(item_id, created_at)` och
 * `(user_id, created_at)` är läsvägarna issue 108 och 116 använder;
 * `(user_id, created_at)` behövs särskilt för läsregelns andra punkt, där en
 * användare ser sina egna rader.
 *
 * Släppningen körs före kolumnen läggs till: `dropForeign` mot ett index som
 * ännu inte finns hade varit en gissning, och på MariaDB är ordningen den
 * främmande nyckeln kräver. `down()` sätter tillbaka nycklarna och är
 * medvetet ofullständig på samma sätt som förlagan
 * 2026_09_10_030000_add_item_id_to_container_access_and_invitation: en rad
 * som pekar på ett gallrat subjekt kan inte få sin nyckel tillbaka, och
 * `down()` finns för testsviten — migrationer rullas aldrig tillbaka i
 * produktion (AGENTS.md § Databaskonventioner).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropForeign(['account_id']);
            $table->dropForeign(['user_id']);
            $table->dropForeign(['container_id']);
        });

        Schema::table('audit_log', function (Blueprint $table) {
            $table->unsignedBigInteger('item_id')->nullable()->after('container_id');
            $table->index(['item_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_log', function (Blueprint $table) {
            $table->dropIndex(['item_id', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn('item_id');
        });

        // FK:n läggs tillbaka på den befintliga kolumnen — sqlite bygger om
        // tabellen och får dem med, MariaDB får dem som ADD CONSTRAINT.
        // Nyckelns form i produktion bevisas av migreringsjobbet mot
        // MariaDB; `down()` finns för testsviten.
        Schema::table('audit_log', function (Blueprint $table) {
            $table->foreign('account_id')->references('id')->on('account')->onDelete('restrict');
            $table->foreign('user_id')->references('id')->on('user')->onDelete('restrict');
            $table->foreign('container_id')->references('id')->on('container')->onDelete('restrict');
        });
    }
};

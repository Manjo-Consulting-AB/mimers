<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 158 · En bilaga kan tillhöra en container. Se [[ADR-0047 Containerns
 * bild]] § Beslut, [[Filer och lagring]] § attachment och
 * [[Konton och åtkomst]] § container.
 *
 * `attachment.item_id` blir NULLBAR och `attachment.container_id` läggs till,
 * också den nullbar, med ON DELETE RESTRICT som husets nycklar. Ett
 * CHECK-villkor kräver att EXAKT EN av dem är satt: en bilaga hör till ett
 * item eller till en container, aldrig till båda och aldrig till ingen.
 * Villkoret är det som gör den nullbarheten säker — utan det vore "ingen
 * ägare" ett tillstånd databasen tillät, och varje läsare hade behövt hantera
 * det.
 *
 * Indexet blir `(container_id, deleted_at)`, spegelbilden av `(item_id,
 * deleted_at)` som finns sedan 16a. Det är den väg papperskorgens listning och
 * containerns bilageuppslag läser, och det följer regeln i AGENTS.md
 * § Databaskonventioner: varje index som används för listning innehåller
 * `deleted_at`.
 *
 * `container.cover_attachment_id` är förlagan från `item.cover_attachment_id`
 * (issue 93, 2026_09_21_010000): en NULLBAR pekare till en av containerns
 * EGNA bilagor. Vilken bild som är containerns är ett faktum om CONTAINERN
 * och bor därför där — ingen `is_cover`, ingen flagga på `attachment`.
 *
 * **ON DELETE SET NULL, till skillnad från husets RESTRICT.** Avvikelsen är
 * densamma som för itemets omslag och står i [[ADR-0041 Itemets vy]]
 * § Konsekvenser: en preferens får aldrig hindra papperskorgens gallring.
 * Bilagor raderas HÅRT av App\Actions\Trash\PurgeContainer och
 * App\Actions\Attachment\PurgeAttachment, och en RESTRICT här hade fällt
 * båda med ett främmandenyckelfel — i drift, i en nattlig körning, i stället
 * för i en testsvit. Pekaren nollställs alltså när bilagan försvinner.
 *
 * ATT ETT CHECK-VILLKOR INTE KAN UTTRYCKA "EGEN" är medvetet: att
 * `cover_attachment_id` pekar på en bilaga vars `container_id` är containerns
 * egen upprätthålls i App\Actions\Container\SetContainerCover, precis som för
 * itemet (ADR-0047 § Beslut, andra stycket).
 *
 * MIGRERINGEN ÄR ADDITIV OCH ÄNDRAR INGEN BEFRÅD RAD. `MODIFY COLUMN ...
 * NULL` på MariaDB är en metadataändring, och ingen rad skrivs av den här
 * filen. Att göra en NOT NULL-kolumn nullbar är en vidgning — varje värde som
 * fick plats förut får plats nu. Expand/contract: den destruktiva delen, om
 * `item_id` någonsin ska bli obligatorisk igen, hör till en senare release.
 *
 * `down()` är förlustbringande så fort en containerbilaga har skrivits: den
 * sätter tillbaka NOT NULL och faller då på MariaDB. Den finns för testsviten
 * — migrationer rullas aldrig tillbaka i produktion (AGENTS.md
 * § Databaskonventioner).
 *
 * CHECK-villkoret och främmande nycklarna rörs bara på mysql. sqlite
 * (testsviten) kan inte lägga till ett CHECK i efterhand och
 * SQLiteGrammar::compileForeign() genererar ingen SQL för en FK som läggs till
 * i en befintlig tabell — samma två luckor som 2026_09_10_030000 beskriver.
 * Kolumnerna finns och är nullbara överallt; villkorets och nycklarnas form
 * bevisas av .github/workflows/migreringar.yml mot MariaDB, och av
 * tests/Feature/Attachment/ContainerbildTest.php när sviten körs mot mysql.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attachment', function (Blueprint $table) {
            // Ingen `constrained()` på item_id: nyckeln finns redan sedan
            // create-migreringen, och att deklarera den igen hade lagt en
            // andra. `change()` rör kolumnen, inte nyckeln — samma form som
            // 2026_09_26_010000.
            $table->foreignId('item_id')->nullable()->change();

            $table->foreignId('container_id')
                ->nullable()
                ->after('item_id')
                ->constrained('container')
                ->onDelete('restrict');

            $table->index(['container_id', 'deleted_at']);
        });

        // Pekaren läggs efter `template_source_id`, sist av containerns egna
        // fält: den är containerns egen uppgift, inte historik om raden.
        Schema::table('container', function (Blueprint $table) {
            $table->foreignId('cover_attachment_id')
                ->nullable()
                ->after('template_source_id')
                ->constrained('attachment')
                ->onDelete('set null');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // `(item_id IS NULL) <> (container_id IS NULL)` är sant exakt när
            // en av dem är satt. Båda satta: falskt. Ingen satt: falskt.
            DB::statement('ALTER TABLE attachment ADD CONSTRAINT attachment_owner_check CHECK ((item_id IS NULL) <> (container_id IS NULL))');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE attachment DROP CONSTRAINT attachment_owner_check');
        }

        Schema::table('container', function (Blueprint $table) {
            $table->dropForeign(['cover_attachment_id']);
            $table->dropColumn('cover_attachment_id');
        });

        Schema::table('attachment', function (Blueprint $table) {
            $table->dropForeign(['container_id']);
            $table->dropIndex(['container_id', 'deleted_at']);
            $table->dropColumn('container_id');

            // Sist: en containerbilaga har item_id = NULL, och kolumnen kan
            // inte bli NOT NULL medan en sådan rad finns. Gallra dem först —
            // `down()` är förlustbringande redan på det villkoret, och det
            // står i filens docblock.
            DB::table('attachment')->whereNull('item_id')->delete();

            $table->foreignId('item_id')->nullable(false)->change();
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 379 · En bilaga kan tillhöra en person, så att profilbilden får samma
 * dedup, referensräkning, miniatyrer, virusskanning, kvot och leverans som
 * varje annan fil. Se [[ADR-0076 Profilbilden]] § 1, [[Filer och lagring]]
 * § attachment och [[Konton och åtkomst]] § user.
 *
 * `attachment.user_id` är nullbar, med ON DELETE RESTRICT som husets nycklar,
 * och får indexet `(user_id, deleted_at)` — spegelbilden av `(item_id,
 * deleted_at)` och `(container_id, deleted_at)`. Det är den väg en framtida
 * listning av personens bilagor läser, och det följer AGENTS.md
 * § Databaskonventioner: varje index som används för listning innehåller
 * `deleted_at`.
 *
 * CHECK-VILLKORET VIDGAS från *exakt en av `item_id` och `container_id`* till
 * *exakt en av `item_id`, `container_id` och `user_id`*. Det gamla villkoret
 * (`attachment_owner_check`, 2026_09_27_020000) släpper inte igenom en rad
 * med bara `user_id`, så det måste ersättas — inte kompletteras. Uttrycket
 * `(item_id IS NULL) + (container_id IS NULL) + (user_id IS NULL) = 2` är
 * sant exakt när en av de tre är satt: två satta ger 1, ingen satt ger 3.
 *
 * `user.avatar_attachment_id` är personens pekare till sin bild, i samma form
 * som `container.cover_attachment_id` (2026_09_27_020000): nullbar FK mot
 * `attachment`, och **ON DELETE SET NULL, till skillnad från husets
 * RESTRICT**. Issuen skriver RESTRICT i samma andetag som den säger "i samma
 * form som container.cover_attachment_id", men de två kan inte båda gälla —
 * och containerns form är den som fungerar. Skälet är detsamma som där: en
 * pekare är en PREFERENS, och en preferens får aldrig hindra en gallring.
 * App\Actions\Attachment\PurgeAttachment raderar bilagan HÅRT, och en
 * RESTRICT här hade fällt varje väg som rensar en profilbild — i drift, i en
 * nattlig körning, i stället för i en testsvit. Pekaren nollställs alltså när
 * bilagan försvinner.
 *
 * ATT ETT CHECK-VILLKOR INTE KAN UTTRYCKA "EGEN" är medvetet: att
 * `avatar_attachment_id` pekar på en bilaga vars `user_id` är personens egen
 * upprätthålls i den action som sätter pekaren (issue 380), precis som för
 * containern (ADR-0076 § 1).
 *
 * MIGRERINGEN ÄR ADDITIV OCH ÄNDRAR INGEN BEFINTLIG RAD. Två nullbara
 * kolumner läggs till, och villkoret vidgas — varje rad som fick plats förut
 * får plats nu, eftersom de nya kolumnerna är NULL på alla befintliga rader.
 * Expand/contract: den destruktiva delen hör till en senare release.
 *
 * CHECK-villkoret och främmande nycklarna rörs bara på mysql. sqlite
 * (testsviten) kan inte lägga till ett CHECK i efterhand, och
 * SQLiteGrammar::compileForeign() genererar ingen SQL för en FK som läggs till
 * i en befintlig tabell — samma två luckor som 2026_09_27_020000 beskriver.
 * Kolumnerna finns och är nullbara överallt; villkorets form bevisas av
 * .github/workflows/migreringar.yml mot MariaDB, som kör
 * tests/Feature/Bilaga/PersonbilagaTest.php mot samma motor som staging och
 * produktion.
 *
 * `down()` är förlustbringande så fort en personbilaga har skrivits: det
 * gamla villkoret tillåter inte en rad med bara `user_id`, så sådana rader
 * gallras innan villkoret sätts tillbaka. Den finns för testsviten —
 * migrationer rullas aldrig tillbaka i produktion (AGENTS.md
 * § Databaskonventioner).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('attachment', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('container_id')
                ->constrained('user')
                ->onDelete('restrict');

            $table->index(['user_id', 'deleted_at']);
        });

        // Pekaren läggs efter personens egna inställningar, sist av de
        // kolumner som hör till personen och inte till historiken om henne.
        Schema::table('user', function (Blueprint $table) {
            $table->foreignId('avatar_attachment_id')
                ->nullable()
                ->after('notifications_cleared_at')
                ->constrained('attachment')
                ->onDelete('set null');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Det gamla villkoret kräver exakt en av TVÅ ägare och släpper
            // inte igenom en personbilaga. Det ersätts, inte kompletteras:
            // två villkor på samma kolumner hade varit två sanningar om
            // samma sak.
            DB::statement('ALTER TABLE attachment DROP CONSTRAINT attachment_owner_check');
            DB::statement('ALTER TABLE attachment ADD CONSTRAINT attachment_owner_check CHECK ((item_id IS NULL) + (container_id IS NULL) + (user_id IS NULL) = 2)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            // Villkoret refererar `user_id` och måste bort FÖRE kolumnen.
            DB::statement('ALTER TABLE attachment DROP CONSTRAINT attachment_owner_check');

            // Personbilagorna kan det gamla villkoret inte bära — de har
            // varken item_id eller container_id. `down()` är förlustbringande
            // redan på det villkoret, se filens docblock.
            DB::table('attachment')->whereNull('item_id')->whereNull('container_id')->delete();
        }

        Schema::table('user', function (Blueprint $table) {
            $table->dropForeign(['avatar_attachment_id']);
            $table->dropColumn('avatar_attachment_id');
        });

        Schema::table('attachment', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'deleted_at']);
            $table->dropColumn('user_id');
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE attachment ADD CONSTRAINT attachment_owner_check CHECK ((item_id IS NULL) <> (container_id IS NULL))');
        }
    }
};

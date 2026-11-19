<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 417 · Tabellerna `item_field` och `item_field_value`, se
 * [[ADR-0089 Egna fält på items]] § Riktning 1–4 och 9 och
 * [[Items och organisation]] § item_field och § item_field_value.
 *
 * `item_field` bär DEFINITIONEN: en rubrik och en typ, på containern
 * (`item_id` NULL) eller på ett enskilt item (`item_id` satt). Typen är
 * `text`, `number` eller `date` och spärras av ett CHECK-villkor på mysql —
 * sqlite (testsviten) saknar stöd för CHECK och hoppar över det, samma
 * drivrutinsgrening som `container` och `account` gjorde i sina migreringar.
 *
 * `item_field_value` bär VÄRDET per item och fält. Tabellen skapas här men
 * skrivs först i issue 418 — kolumnerna står i datamodellen och ändras inte
 * senare. Ingen `ulid`: värdet adresseras med item och fält, se
 * [[Items och organisation]]. Ingen `deleted_at`: ett tömt värde är bara
 * borta, och ett fälts värden följer definitionen in i papperskorgen.
 *
 * **Båda tabellerna räknas mot kvoten** (ADR-0081 § 2) och står därför i
 * App\Support\Usage\MetadataSize::COLUMNS från samma stund de finns.
 * `billed_account_id` är "den som skapar raden betalar" (§ 3).
 *
 * **Rubriken jämförs utan hänsyn till skiftläge.** mysql gör det genom
 * anslutningens `utf8mb4_unicode_ci`; sqlite är annars skiftlägeskänslig och
 * får `collate nocase` på kolumnen, precis som `tag.name` (se
 * 2026_08_30_000000_create_tag_table.php). Krocken prövas i applikationen,
 * inte av ett unikt index.
 *
 * **Filen ligger före `2026_11_15_030000` med flit**, trots att issuen är
 * senare, av samma skäl som item_version-migrationen: den fyller
 * `usage_counter.metadata_bytes` genom App\Support\Usage\CountedRows, vars
 * tabellista kommer ur MetadataSize::COLUMNS — och den listan bär nu de här
 * två tabellerna. På en färsk databas hade ifyllningen annars frågat efter
 * tabeller som ännu inte fanns. De är tomma när ifyllningen körs.
 *
 * `item_field.item_id` kaskar: ett items egna fält tas bort med itemet
 * ([[ADR-0089 Egna fält på items]] § Riktning 2). `container_id` är RESTRICT
 * som alla andra containervägar — en container kan inte gallras medan ett
 * fält pekar på den, och App\Actions\Trash\PurgeContainer rensar fälten före.
 */
return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = Schema::getConnection()->getDriverName() === 'sqlite';

        Schema::create('item_field', function (Blueprint $table) use ($isSqlite) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('container_id')->constrained('container')->onDelete('restrict');
            $table->foreignId('item_id')->nullable()->constrained('item')->cascadeOnDelete();
            $table->string('label', 100)->collation($isSqlite ? 'nocase' : null);
            $table->string('type', 10);
            $table->integer('position')->default(1);
            $table->foreignId('billed_account_id')->index()->constrained('account')->onDelete('restrict');
            $table->softDeletes();
            // Agentmärkningen som alla tabeller papperskorgen listar bär
            // (issue 406 · [[ADR-0087 MCP-server för agenter]] Riktning 8
            // och 9): en radering gjord av en agent ska gå att se och
            // ångra i ett svep. Kolumnerna är MEDVETET utan främmande nyckel
            // — agenttabellen skapas i en senare migration än den här, och
            // `item_version.replaced_by_agent_id` valde samma form av samma
            // skäl. En bortkopplad agent lämnar ett id utan rad; namnet
            // faller då tillbaka på användaren, som för en raderad användare.
            $table->unsignedBigInteger('deleted_by_agent_id')->nullable();
            $table->char('agent_deletion_batch', 26)->nullable();
            $table->index('agent_deletion_batch');
            $table->timestamps();

            $table->index(['container_id', 'item_id', 'deleted_at', 'position']);
            $table->index(['item_id', 'deleted_at']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE item_field ADD CONSTRAINT item_field_type_check CHECK (type IN ('text', 'number', 'date'))");
        }

        Schema::create('item_field_value', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained('item')->cascadeOnDelete();
            $table->foreignId('item_field_id')->constrained('item_field')->cascadeOnDelete();
            $table->string('value_text', 255)->nullable();
            $table->decimal('value_number', 20, 6)->nullable();
            $table->date('value_date')->nullable();
            $table->foreignId('billed_account_id')->index()->constrained('account')->onDelete('restrict');
            $table->timestamps();

            $table->unique(['item_id', 'item_field_id']);
            $table->index(['item_field_id', 'value_number']);
            $table->index(['item_field_id', 'value_date']);

            if (Schema::getConnection()->getDriverName() === 'mysql') {
                $table->fullText('value_text');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_field_value');
        Schema::dropIfExists('item_field');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 421 · Tabellen `container_template`, se
 * [[Items och organisation]] § container_template och
 * [[ADR-0089 Egna fält på items]] § Riktning 6 och 9.
 *
 * En mall är en KOPIA av en containers kategoriträd och fält, inte en
 * koppling: ingenting i containern pekar tillbaka på raden här, och en
 * ändring i mallen slår aldrig igenom i en container som redan finns.
 * Kolumnen `container.template_source_id` är en annan sak (ADR-0002) och rörs
 * inte.
 *
 * `content` är JSON med kategoriträdet och fälten i ordning —
 * `{"categories": [{"name", "children": [...]}], "fields": [{"label", "type"}]}`.
 * Inga items och inga egna fält på items: mallen bär bara det som hör till
 * containern.
 *
 * **Filen ligger före `2026_11_15_030000` med flit**, av samma skäl som
 * item_version- och item_field-migrationerna: den fyller
 * `usage_counter.metadata_bytes` genom App\Support\Usage\CountedRows, och
 * tabellen räknas redan då. De är tom när ifyllningen körs.
 *
 * **Tabellen är kontonivå, inte containernivå.** Den har därför ingen
 * `container_id`; betalaren är `billed_account_id` och ägaren `account_id`.
 * App\Support\Usage\MetadataSize bär den i ACCOUNT_COLUMNS, inte i COLUMNS,
 * eftersom COLUMNS är listan över tabeller som hänger på en container.
 *
 * Mjukradering enligt [[ADR-0008 Soft delete och papperskorg]] är ett skydd
 * mot buggar, men till skillnad från annat mjukraderat släpper mallen sina
 * byte vid borttagningen (issue 421). Papperskorgen får ingen ny typ, så
 * mallen kan inte återställas och gallras aldrig; byte som räknades vidare
 * hade då aldrig släppts. App\Support\Usage\MetadataSize ger en slängd mall
 * vikten noll och App\Support\Usage\CountedRows räknar bara levande mallar.
 * Avvikelsen från [[ADR-0081 All lagrad data räknas mot kvoten]] § 3 ska
 * skrivas in i [[Items och organisation]] § container_template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('container_template', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('account_id')->constrained('account')->onDelete('restrict');
            $table->string('name', 255);
            $table->json('content');
            $table->foreignId('billed_account_id')->index()->constrained('account')->onDelete('restrict');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['account_id', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('container_template');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 18 · Miniatyrer. Se [[Filer och lagring]] § image_derivative.
 *
 * Derivaten genereras vid uppladdning, inte vid visning (issue 18 § Beslut
 * 1 och 3): en rad per variant och stored_file, `thumb` = 320 px och
 * `medium` = 1024 px på längsta sidan (§ Beslut 2). Två varianter, samma
 * format som källan — jpeg/png/webp, se § Beslut 4.
 *
 * Ingen `ulid`: derivatet syns aldrig som egen resurs i API:et, det nås via
 * bilagan (19a) — samma skäl som item_tag och account_user. Ingen
 * `deleted_at`: ett derivat är inte användarskapat innehåll utan en härledd
 * fil, och det lever exakt så länge dess `stored_file` gör. Gallringen i 17b
 * tar bort derivatraderna (och filerna) i samma transaktion som bytena;
 * FK:n är RESTRICT, så derivatraderna måste bort före `stored_file`-raden
 * (§ Beslut 7).
 *
 * UNIQUE `(stored_file_id, variant)` — en bild har högst ett `thumb` och
 * ett `medium`, och unikhetsvillkoret är det som gör en omkörning av jobbet
 * ofarlig (§ Beslut 1).
 *
 * `byte_size` finns för kapacitetsplanering. Derivaten räknas ALDRIG mot
 * användarens kvot (§ Beslut 6): kvoten mäter `attachment` →
 * `stored_file.byte_size`, ingenting annat. Den som skriver M4:s räknare
 * (issue 26) får inte summera in den här kolumnen i något tal som visas för
 * en användare eller jämförs mot en plangräns.
 *
 * CHECK-villkoret läggs bara på mysql; sqlite (test) saknar stöd för
 * ALTER TABLE ... ADD CONSTRAINT. Se förlagan
 * 2026_08_29_000000_create_invitation_table.php.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('image_derivative', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stored_file_id')->constrained('stored_file')->onDelete('restrict');
            $table->string('variant', 20);
            $table->string('storage_path', 255);
            $table->unsignedBigInteger('byte_size');
            $table->timestamps();

            $table->unique(['stored_file_id', 'variant']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE image_derivative ADD CONSTRAINT image_derivative_variant_check CHECK (variant IN ('thumb', 'medium'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('image_derivative');
    }
};

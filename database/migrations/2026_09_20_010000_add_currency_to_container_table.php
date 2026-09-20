<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 85 · Valutan ärvs nedåt. Se [[ADR-0037 Valutans arv]].
 *
 * Containern ÄRVER kontots valuta och kan ange en egen. Kolumnen är därför
 * NULLBAR, och `null` är det ärliga värdet för "containern har ingen egen
 * valuta" — en tom sträng hade varit precis den sentinel
 * [[ADR-0004 Fria taggar och kategorier]] vill undvika. Arvet formuleras på
 * ett ställe: `App\Models\Container::effectiveCurrency()`.
 *
 * Ingen default och ingen datamigrering av befintliga rader: en container
 * utan egen valuta följer kontot, vilket är exakt vad `null` betyder.
 *
 * `cost_entry.currency` rörs inte av den här migrationen och inte av någon
 * annan i issuen. Raden bär kvar sin valuta, obligatorisk — det som står i
 * en rad är vad som betalades, och ett byte av containerns valuta märker
 * aldrig om historiken ([[ADR-0037 Valutans arv]] § Beslut).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->char('currency', 3)->nullable()->after('kind');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('container', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};

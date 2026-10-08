<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 262 · Vouchrar och stängd registrering. Se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 4 och [[Konton och åtkomst]]
 * § voucher.
 *
 * En voucher är en inbjudningskod: den släpper in en ny person i betan och
 * ger henne den plan den bär. Det finns inte två begrepp — en inbjudan och
 * en rabattkod — utan ett (ADR-0055 § 2).
 *
 * `code_hash` är en SHA-256-hex av den NORMALISERADE koden, aldrig koden
 * själv, samma grepp som `invitation.token_hash` (ADR-0055 § 4 och § 5):
 * en läckt databasdump ska inte innehålla inlösbara koder. Exakt CHAR(64)
 * av samma skäl som i `invitation` — en hashkollision blir ett skrivfel i
 * stället för en tyst förväxling av två koder.
 *
 * Ingen `deleted_at`: en voucher är inte användarskapat innehåll, och
 * `revoked_at` är dess livscykel (ADR-0055 § 4). Raden raderas aldrig —
 * `voucher_redemption` pekar på den med RESTRICT och bär historiken över
 * vem som kom in på vilken kod.
 *
 * `duration_days` är hur länge planen gäller från inlösen, inte från
 * skapandet: koden kan ligga outnyttjad en tid och ska ändå ge full tid.
 *
 * `used_count` räknas upp inne i inlösens transaktion
 * (App\Actions\Voucher\RedeemVoucher) — kolumnen är sanningen om hur många
 * uttag som gjorts, och `max_uses` är taket.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('voucher', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->char('code_hash', 64)->unique();
            $table->string('label', 191)->nullable();
            $table->foreignId('plan_id')->constrained('plan')->onDelete('restrict');
            $table->unsignedInteger('duration_days');
            $table->unsignedInteger('max_uses');
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('grants_registration');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher');
    }
};

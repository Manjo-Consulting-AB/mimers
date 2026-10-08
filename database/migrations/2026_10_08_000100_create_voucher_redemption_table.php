<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 262 · Vouchrar och stängd registrering. Se [[ADR-0055
 * Inbjudningskoder och stängd registrering]] § 4 och [[Konton och åtkomst]]
 * § voucher.
 *
 * En rad per inlösen: vem som kom in, på vilken kod, i vilket konto. Raden
 * skrivs i samma transaktion som `voucher.used_count` räknas upp
 * (App\Actions\Voucher\RedeemVoucher) och är historiken över att koden
 * använts.
 *
 * `SET NULL` på `account_id` och `user_id` är ett medvetet undantag från
 * RESTRICT (ADR-0055 § 4): en raderad person eller ett raderat konto
 * ([[ADR-0045 Radering av konto och person]]) ska inte hindras av en
 * inlösen. Raden står kvar med `account_id = NULL` — den är beviset för att
 * koden förbrukats, och `used_count` på vouchern bär samma sanning.
 *
 * Unikt index på (`voucher_id`, `account_id`): en kod får lösas in högst en
 * gång per konto. `max_uses` > 1 betyder flera KONTON, inte flera inlösen på
 * samma konto. Indexet är backstoppet; App\Actions\Voucher\RedeemVoucher
 * prövar samma sak inne i låset så felet blir ett valideringsfel och inte en
 * databasdom.
 *
 * Ingen `ulid` och ingen `deleted_at`: raden identifieras aldrig i en rutt
 * och är inget innehåll — samma form som `attachment_open` och
 * `recent_visit`.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('voucher_redemption', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')->constrained('voucher')->onDelete('restrict');
            $table->foreignId('account_id')->nullable()->constrained('account')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('user')->onDelete('set null');
            $table->timestamps();

            $table->unique(['voucher_id', 'account_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('voucher_redemption');
    }
};

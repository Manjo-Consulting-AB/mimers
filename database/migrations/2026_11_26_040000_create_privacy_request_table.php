<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 481 · M87 Integritetsbegäranden. Se [[ADR-0095 Integritetsbegäranden]]
 * § 3–§ 5 och [[Konton och åtkomst]] § privacy_request.
 *
 * Tabellen är platsen där en begäran från en registrerad registreras.
 * Ytan är kommandon, inte en sida (ADR-0095 § 1), och därför är `ulid` den
 * enda identifieraren utåt — bilagornas sökväg på disken börjar med den
 * (issue 486) — medan referensen är det handläggaren ger den som begärt.
 *
 * **Referensen är `PR-` och åtta slumpade tecken i Crockfords base32.** Ett
 * löpnummer hade läckt hur många begäranden vi fått (ADR-0095 § 3), så
 * kolumnen är elva tecken och unik; genereringen bor i
 * App\Actions\Privacy\OpenPrivacyRequest, som prövar unikheten innan raden
 * skrivs.
 *
 * **`account_id` och `user_id` är identifierare utan främmande nycklar**,
 * samma avvägning som `legal_hold` och `audit_log`: ärendet ska överleva den
 * radering det beställer. Med en nyckel hade DeleteAccount och DeleteUser
 * fallit på varje konto som någonsin begärt något. Kolumnerna sätts först
 * när identiteten är prövad (ADR-0095 § 2).
 *
 * **Hela tabellen skapas här**, också kolumnerna för begränsningen och
 * avslutet som senare issuers skriver till: `restriction_scope`,
 * `restricted_at`, `restriction_lifted_at`, `outcome_reason` och
 * `closed_at`. M87 får bara en migration för ärendet, och en expand/contract
 * hade delat samma tabell över flera releaser utan vinst.
 *
 * Ingen `deleted_at`: gallringen (issue 487) raderar på riktigt 36
 * kalendermånader efter `closed_at`, och aldrig ett öppet ärende.
 *
 * Tabellen skriver inget till `audit_log` eller `security_log`, och
 * mätningen läser den inte (ADR-0095 § 3): ägarkontot läser händelseloggen,
 * och en begäran från en låntagare eller medarbetare får inte synas där.
 *
 * Indexen är de tre läsvägarna: `(status, deadline_at)` är listans sortering
 * (issue 483), `(closed_at)` är gallringens frist, och `(account_id)` och
 * `(user_id)` är uppslagningen när ett konto eller en person raderas.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('privacy_request', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('reference', 11)->unique();
            $table->string('type', 30);
            $table->string('status', 30);
            $table->string('priority', 10)->default('normal');
            $table->string('requester_name', 255);
            $table->string('requester_email', 255);
            $table->string('on_behalf_of', 255)->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role', 20)->default('undetermined');
            $table->string('assignee', 120)->nullable();
            $table->text('description');
            $table->timestamp('received_at');
            $table->timestamp('deadline_at');
            $table->unsignedTinyInteger('extended_months')->default(0);
            $table->text('restriction_scope')->nullable();
            $table->timestamp('restricted_at')->nullable();
            $table->timestamp('restriction_lifted_at')->nullable();
            $table->text('outcome_reason')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'deadline_at']);
            $table->index(['closed_at']);
            $table->index(['account_id']);
            $table->index(['user_id']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE privacy_request ADD CONSTRAINT privacy_request_type_check CHECK (type IN ('access', 'rectification', 'erasure', 'restriction', 'portability', 'objection', 'consent_withdrawal', 'other'))");
            DB::statement("ALTER TABLE privacy_request ADD CONSTRAINT privacy_request_status_check CHECK (status IN ('received', 'verification_required', 'under_assessment', 'in_progress', 'awaiting_requester', 'completed', 'partially_completed', 'rejected', 'closed'))");
            DB::statement("ALTER TABLE privacy_request ADD CONSTRAINT privacy_request_priority_check CHECK (priority IN ('low', 'normal', 'high'))");
            DB::statement("ALTER TABLE privacy_request ADD CONSTRAINT privacy_request_role_check CHECK (role IN ('controller', 'processor', 'both', 'undetermined'))");
            DB::statement('ALTER TABLE privacy_request ADD CONSTRAINT privacy_request_extended_months_check CHECK (extended_months <= 2)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('privacy_request');
    }
};

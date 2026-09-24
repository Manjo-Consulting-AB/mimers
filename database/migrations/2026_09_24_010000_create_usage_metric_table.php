<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 114 · Mätningen. Se [[ADR-0043 Tre loggar]] § Mätningen. Tabellen bär
 * anonyma summor: antal loggrader per dag, källa, handling och plan. Den
 * läses bara av oss och sparas för evigt — anonyma summor omfattas inte av
 * GDPR (ADR § Motivering), och en dag som gallrats ur loggen finns kvar här.
 *
 * **Ingen kolumn pekar på en person, ett konto eller en container.** Ingen
 * `user_id`, ingen `account_id`, ingen `container_id`, ingen IP-adress och
 * inget `ulid`: en rad som kan knytas till en användare vore personuppgifter
 * med evig livslängd. Kolumnerna är de fyra dimensionerna och talet.
 *
 * `date` är mätdagen, `source` är vilken av de två loggarna raden räknats ur
 * (`audit_log` eller `security_log`) och `action` är handlingens namn ur
 * respektive logg — eller `other`, som grupper under tröskeln hamnar i.
 * `plan` är kontots plankod när jobbet körde; `unknown` när kontot inte
 * längre finns, och `mixed` när en `other`-grupp slagits ihop över planerna
 * (AggregatesUsageMetrics::PLAN_MIXED). De två får inte blandas: `unknown` är
 * ett mätbart mått på aktivitet från raderade konton.
 *
 * **Det unika indexet är idempotensen.** En dag, en källa, en handling och en
 * plan förekommer högst en gång, så en andra körning för samma dag kan inte
 * dubblera raderna — jobbet skriver om mängden i stället för att lägga till.
 *
 * `source` är VARCHAR med CHECK-villkor, aldrig MySQL ENUM (AGENTS.md
 * § Databaskonventioner). Villkoret läggs bara på mysql: sqlite (test) saknar
 * stöd för ALTER TABLE ... ADD CONSTRAINT.
 *
 * `action` är 60 tecken, samma bredd som `audit_log.action` och
 * `security_log.action` — namnen kommer därifrån. `plan` är 40, samma som
 * `plan.code`. Det finns ingen främmande nyckel mot `plan`: mätningen
 * överlever planen den räknades på, precis som loggen överlever sitt subjekt.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('usage_metric', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('source', 20);
            $table->string('action', 60);
            $table->string('plan', 40);
            $table->unsignedInteger('count');
            $table->timestamps();

            $table->unique(['date', 'source', 'action', 'plan']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE usage_metric ADD CONSTRAINT usage_metric_source_check CHECK (source IN ('audit', 'security'))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('usage_metric');
    }
};

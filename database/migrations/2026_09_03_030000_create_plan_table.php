<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Issue 25 · Planer och rättigheter. Se [[Planer och kvoter]] § plan och
 * § Gränserna i MVP, samt [[ADR-0014 Prismodell]].
 *
 * `plan` beskriver vad ett konto får — gränserna ligger i JSON-kolumnen
 * `limits`, så ett nytt B2B-erbjudande är en ny rad, inte ny kod. Inget ulid
 * och inget deleted_at: en plan är ingen resurs i API:et (identifieraren utåt
 * är `code`) och inte användarskapat innehåll, se issue 25 § Beslut 1.
 *
 * Rader för free och pro skapas här, inte i en seeder: deploy/deploy.sh kör
 * bara `php artisan migrate --force`, och ett rättighetslager utan planrader
 * skulle neka antingen allt eller ingenting (issue 25 § Beslut 2). Rader via
 * DB::table, aldrig Plan::create — en gammal migration får inte bero på en
 * modell som kan ändras i en senare release.
 *
 * Rader för free och pro skapas i seedPlans(), som up() anropar sist.
 * seedPlans() är public och idempotent via updateOrInsert — en omkörd
 * migration ska inte dubblera raderna (test: "en omkörd migration dubblerar
 * inte planraderna"). Någon hasTable-vakt finns inte: Laravel kör aldrig
 * samma migration två gånger, och en tabell som redan finns med fel form ska
 * krascha deployen, inte tigas ihjäl.
 *
 * Pros `max_file_bytes` är 64 MB, inte 100 MB: serverns `upload_max_filesize`
 * hos inleed står på 64M (`public/.htaccess`, speglad i `config/files.php`),
 * och en plangräns över det tekniska taket kan aldrig slå i — uppladdningen
 * dör som ett valideringsfel i StoreAttachmentRequest långt före
 * Entitlements. Talen flyttas tillsammans, se [[ADR-0014 Prismodell]]
 * § Konsekvenser.
 *
 * Pengar är BIGINT i minsta valutaenhet + CHAR(3), aldrig flyttal (AGENTS.md
 * § Databaskonventioner). Byten räknas binärt — 1 GB = 1 GiB. CHECK-villkoret
 * läggs bara på mysql; sqlite (test) saknar stöd för ALTER TABLE ... ADD
 * CONSTRAINT, och uppräkningen är VARCHAR, aldrig MySQL ENUM.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('plan', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->bigInteger('price_amount');
            $table->char('price_currency', 3);
            $table->string('billing_period', 10);
            $table->json('limits');
            $table->boolean('is_public')->default(true);
            $table->timestamps();
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE plan ADD CONSTRAINT plan_billing_period_check CHECK (billing_period IN ('year', 'month'))");
        }

        $this->seedPlans();
    }

    /**
     * Skapa de inbyggda planraderna. Idempotent, så en omkörning — till
     * exempel i test — inte dubblerar raderna.
     */
    public function seedPlans(): void
    {
        $nu = now();

        foreach ([
            [
                'code' => 'free',
                'name' => 'Free',
                'price_amount' => 0,
                'price_currency' => 'EUR',
                'billing_period' => 'year',
                'limits' => [
                    'containers' => 1,
                    'storage_bytes' => 1024 * 1024 * 1024,
                    'max_file_bytes' => 10 * 1024 * 1024,
                    'shared_users_per_container' => 1,
                    'webhooks' => false,
                    'pdf_binder' => false,
                    'ownership_transfer' => false,
                    'loan_reminders' => false,
                    'cost_reports' => false,
                ],
                'is_public' => true,
                'created_at' => $nu,
                'updated_at' => $nu,
            ],
            [
                'code' => 'pro',
                'name' => 'Pro',
                'price_amount' => 4900,
                'price_currency' => 'EUR',
                'billing_period' => 'year',
                'limits' => [
                    'containers' => null,
                    'storage_bytes' => 25 * 1024 * 1024 * 1024,
                    'max_file_bytes' => 64 * 1024 * 1024,
                    'shared_users_per_container' => null,
                    'webhooks' => true,
                    'pdf_binder' => true,
                    'ownership_transfer' => true,
                    'loan_reminders' => true,
                    'cost_reports' => true,
                ],
                'is_public' => true,
                'created_at' => $nu,
                'updated_at' => $nu,
            ],
        ] as $rad) {
            $rad['limits'] = json_encode($rad['limits'], JSON_THROW_ON_ERROR);

            DB::table('plan')->updateOrInsert(['code' => $rad['code']], $rad);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan');
    }
};

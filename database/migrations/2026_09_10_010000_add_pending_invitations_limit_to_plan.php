<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Issue 48 · Tak för utestående inbjudningar. Se [[Planer och kvoter]] § plan
 * och [[ADR-0017 Missbruksvektorer]] § 5.
 *
 * Den tionde nyckeln i `plan.limits`, `pending_invitations`, backfillas in i
 * de befintliga planraderna. Migrationer rullas aldrig tillbaka i produktion
 * (AGENTS.md § Databaskonventioner), så `create_plan_table.php` rörs inte: en
 * ny kolumn eller nyckel är ett additivt steg i en NY migration.
 *
 * Backfillen är inte en trevlighet utan villkoret för att koden ska fungera:
 * Plan::planLimit() KASTAR på en okänd nyckel (issue 25 § Beslut 7), så en
 * planrad utan `pending_invitations` gör inbjudningsrutten till ett 500-fel.
 *
 * Rader läsas och skrivs med DB::table, aldrig Plan::create() eller modellen —
 * exakt som seedPlans() i create_plan_table.php, av samma skäl: en migration
 * får inte bero på en modell som kan ändras i en senare release.
 *
 * Talen (10 och 100) är platshållare i den mening ADR-0017 § Konsekvenser
 * menar — de ligger i en tabellrad och revideras med en UPDATE, inte genom en
 * kodändring. Ingen if-sats någonstans får nämna ett plannamn (issue 25
 * § Beslut 2).
 */
return new class extends Migration
{
    /**
     * Nyckeln och dess platshållarvärde per plan-kod. Pro är obegränsat på
     * `containers` och `shared_users_per_container` men FÅR ett tak här:
     * vektorn i ADR-0017 § 5 handlar om utskick till overifierade adresser,
     * och ett Pro-konto kan skicka lika många som ett gratiskonto.
     *
     * @var array<string, int>
     */
    private const LIMITS = [
        'free' => 10,
        'pro' => 100,
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::LIMITS as $code => $limit) {
            $row = DB::table('plan')->where('code', $code)->first();

            // Saknas raden finns inget att backfylla. create_plan_table.php
            // skapar free och pro, så i praktiken gör den alltid det — men en
            // migration som kraschar på en frånvarande rad vore en
            // onödig fälla.
            if ($row === null) {
                continue;
            }

            $limits = json_decode($row->limits, true, flags: JSON_THROW_ON_ERROR);
            $limits['pending_invitations'] = $limit;

            DB::table('plan')->where('code', $code)->update([
                'limits' => json_encode($limits, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reverse the migrations — tar bort nyckeln igen. Raden och planen i
     * övrigt lämnas orörda.
     */
    public function down(): void
    {
        foreach (array_keys(self::LIMITS) as $code) {
            $row = DB::table('plan')->where('code', $code)->first();

            if ($row === null) {
                continue;
            }

            $limits = json_decode($row->limits, true, flags: JSON_THROW_ON_ERROR);
            unset($limits['pending_invitations']);

            DB::table('plan')->where('code', $code)->update([
                'limits' => json_encode($limits, JSON_THROW_ON_ERROR),
                'updated_at' => now(),
            ]);
        }
    }
};

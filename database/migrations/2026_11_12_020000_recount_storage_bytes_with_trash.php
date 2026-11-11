<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Issue 1061 · Papperskorgen räknas i kvoten. Se [[ADR-0009 Kvoter och
 * livscykel]] § Uppföljning 2026-11-11 och [[Filer och lagring]] § Kvot
 * kontra faktisk lagring.
 *
 * Regeln ändras: `usage_counter.storage_bytes` är summan av `byte_size` för
 * ALLA kontots bilagor, levande och slängda (`attachment.billed_account_id`).
 * Räknarna som står i tabellen är räknade efter den gamla regeln och lämnar
 * ut de slängda; utan den här omräkningen larmar avstämningsjobbet om drift
 * för varje konto med något i papperskorgen första natten.
 *
 * En UPDATE per konto som har bilagor. Ett konto som saknar rad i
 * `usage_counter` men har bilagor får en (`container_count` 0 — avstämningen
 * rättar containerantalet). Frågebyggaren går på både MariaDB och SQLite.
 */
return new class extends Migration
{
    public function up(): void
    {
        $summor = DB::table('attachment')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->groupBy('attachment.billed_account_id')
            ->selectRaw('attachment.billed_account_id as account_id, COALESCE(SUM(stored_file.byte_size), 0) as total')
            ->pluck('total', 'account_id');

        foreach ($summor as $kontoId => $total) {
            $uppdaterade = DB::table('usage_counter')
                ->where('account_id', $kontoId)
                ->update(['storage_bytes' => (int) $total, 'updated_at' => now()]);

            if ($uppdaterade === 0) {
                DB::table('usage_counter')->insert([
                    'account_id' => $kontoId,
                    'storage_bytes' => (int) $total,
                    'container_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Gör ingenting: migrationer rullas aldrig tillbaka i produktion, fel
     * åtgärdas framåt (AGENTS.md § Databaskonventioner). Räknaren är en cache
     * som avstämningen ändå rättar.
     */
    public function down(): void
    {
        //
    }
};

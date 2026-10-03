<?php

use App\Actions\Plan\GrantInternalPro;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Issue 656 · Internt Pro. Se [[M24 Desktopdesignen]] § Internt Pro.
 *
 * De interna adresserna i config('konton.internal_pro_emails') som redan har
 * konton får Pro här. Registreringen täcker dem som kommer senare, se
 * App\Actions\Auth\CreatesUserWithPersonalAccount — båda anropar samma action,
 * så regeln bor på ett ställe (Beslut 4).
 *
 * Migrationen läser User-modellen och anropar actionen, till skillnad från
 * backfillen i add_pending_invitations_limit_to_plan.php som skriver med
 * DB::table. Det är vad issuen säger: Pro ges genom att skriva en
 * subscription-rad enligt planlogikens vanliga form, och den formen ägs av
 * actionen.
 *
 * down() gör ingenting — expand/contract (AGENTS.md § Databaskonventioner).
 * Att ta bort Pro igen är destruktivt och görs för hand, se issuen.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $action = new GrantInternalPro;

        $adresser = array_map(
            static fn (string $adress): string => mb_strtolower($adress),
            (array) config('konton.internal_pro_emails'),
        );

        // Bara adresserna i listan väljs ut; actionen prövar medlemskapet ännu
        // en gång och är den som äger regeln. LOWER() finns på både mysql och
        // sqlite (test).
        User::query()
            ->whereIn(DB::raw('LOWER(email)'), $adresser)
            ->each(static function (User $user) use ($action): void {
                $action->handle($user);
            });
    }

    /**
     * Reverse the migrations — ingenting, se filens huvudkommentar.
     */
    public function down(): void
    {
        //
    }
};

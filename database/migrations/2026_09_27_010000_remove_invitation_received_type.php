<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Issue 146 · Inbjudningsnotisen som aldrig skickades tas bort. Se
 * [[M22 Redo för testare]] § 146 och [[Notiser]] § notification och
 * § notification_preference.
 *
 * `invitation.received` var en notistyp utan skrivare. Inbjudningsmejlet går
 * direkt via App\Notifications\InvitationNotification och ska fortsätta göra
 * det — en inbjudan kommer från en människa och ska inte vänta på mottagarens
 * tysta timmar — och klockan ritar sin rad ur `invitation`, inte ur
 * `notification` (issue 131). Konstanten är därför borta ur
 * App\Models\Notification, och den här filen städar de rader som pekade på
 * den.
 *
 * TVÅ TABELLER BÄR TYPEN SOM DATA, och båda töms:
 *
 * - `notification_preference`: en rad per (användare, typ, kanal). Rader med
 *   typen raderas. De behövs inte: preferensytan ritar listan ur
 *   NotificationPreferences::EMAIL_DEFAULTS, och en rad för en typ som inte
 *   längre finns där är en rad ingen läser.
 * - `webhook_endpoint.event_types`: en JSON-lista per endpoint. Typen plockas
 *   ur listan där den finns. En endpoint som därefter står utan typer STÅR
 *   KVAR — den är fortfarande en registrerad mottagare med sin url och sin
 *   hemlighet, och en tom prenumeration är ett giltigt tillstånd för ägaren
 *   att fylla på. Att radera raden vore att radera något ingen bett om.
 *
 * Läsningen går genom `chunkById` och filtreringen i PHP, inte genom en
 * JSON-funktion i SQL: `event_types` är JSON på MariaDB men TEXT i
 * sqlite-testsviten, och en `JSON_REMOVE` hade gjort migrationen okörbar där
 * (samma resonemang som CreateNotification::webhookEndpointsFor).
 *
 * `down()` återskapar ingenting. Raderna som raderas är borta, och den typ
 * som togs ur en endpoints lista gick inte att skilja från en typ som aldrig
 * stått där — en återläggning hade gissat. Migrationer rullas aldrig tillbaka
 * i produktion (AGENTS.md § Databaskonventioner); den finns för testsviten.
 */
return new class extends Migration
{
    /**
     * Typen som tas bort, stavad som en sträng och inte genom
     * Notification::TYPE_INVITATION_RECEIVED — konstanten finns inte längre,
     * och en migration ska läsa det schema den skapades mot, inte det som
     * gäller när den körs.
     */
    private const TYPE = 'invitation.received';

    public function up(): void
    {
        DB::table('notification_preference')
            ->where('type', self::TYPE)
            ->delete();

        DB::table('webhook_endpoint')
            ->select(['id', 'event_types'])
            ->orderBy('id')
            ->chunkById(200, function ($endpoints): void {
                foreach ($endpoints as $endpoint) {
                    $types = json_decode($endpoint->event_types ?? '[]', true);

                    if (! is_array($types) || ! in_array(self::TYPE, $types, true)) {
                        continue;
                    }

                    DB::table('webhook_endpoint')
                        ->where('id', $endpoint->id)
                        ->update([
                            'event_types' => json_encode(
                                array_values(array_diff($types, [self::TYPE])),
                                JSON_THROW_ON_ERROR,
                            ),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Ingen återläggning: typen raderas framåt och gissas inte tillbaka.
    }
};

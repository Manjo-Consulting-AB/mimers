<?php

namespace App\Console;

use App\Models\Account;
use App\Models\LegalHold;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Gallrar avslutade integritetsärenden — M87 · issue 487,
 * [[ADR-0095 Integritetsbegäranden]] § 11 och [[Konton och åtkomst]]
 * § privacy_request.
 *
 * Ett ärende bär namn, e-postadress, anteckningar och bilagor om en
 * registrerad. Det sparas i 36 kalendermånader efter `closed_at` och tas
 * sedan bort helt: filerna, bilagorna, händelserna och ärendet. Fristen står
 * i config/privacy.php § retention_months av samma skäl som loggarnas
 * frister i config/loggar.php: den är en uppgift om verksamheten, inte om
 * koden. Den räknas med `subMonths()` och aldrig med dagar — 36 månader är
 * tre år, inte 1095 dagar.
 *
 * **ETT ÖPPET ÄRENDE GALLRAS ALDRIG**, hur gammalt det än är. Fristen räknas
 * ur `closed_at`, så ett ärende utan `closed_at` har ingen frist alls. Ett
 * ärende som öppnats igen har `closed_at` NULL (issue 482) och räknas därför
 * som öppet.
 *
 * **DEN RÄTTSLIGA SPÄRREN GÅR FÖRE** ([[ADR-0043 Tre loggar]] § Den
 * rättsliga spärren). Ett ärende vars konto omfattas av en gällande spärr
 * hoppas över, med samma fråga som PrunesLogs ställer: LegalHold::covers().
 * Frågan läses per kandidat och inte i själva urvalet, så en spärr som sätts
 * medan körningen pågår hinner få verkan. Ett ärende utan konto — begäran
 * kunde inte knytas till en identitet, eller kontot har raderats sedan dess —
 * omfattas inte av någon spärr.
 *
 * **EN GÄLLANDE BEGRÄNSNING HINDRAR INTE GALLRINGEN.** Begränsningen
 * (ADR-0095 § 8) gäller de uppgifter ärendet handlar om, inte ärendet självt,
 * och en avslutad begäran ska inte leva för evigt för att den en gång ledde
 * till en markering.
 *
 * **FILERNA FÖRST, RADERNA SEDAN.** Per ärende i den här ordningen: hela
 * ärendets katalog på disken `privacy`, raderna i privacy_request_attachment,
 * raderna i privacy_request_event och sist ärendet. Faller filborttagningen
 * ligger raderna kvar, så att nästa natt försöker igen — en rad utan fil är
 * ett mindre problem än en fil utan rad, som ingen gallring känner.
 *
 * **INGEN HÄNDELSE SKRIVS.** Händelserna försvinner med ärendet; att
 * gallringen skrivit en händelse på ett ärende den just tagit bort vore en
 * rad utan subjekt. En rad i applikationsloggen med antalet räcker.
 *
 * **OMGÅNGAR OM HÖGST 100 ÄRENDEN**, som PrunesHelpFeedback. Varje omgång
 * väljer sina kandidater på `id` över en kursör, så en spärrad eller
 * misslyckad kandidat inte väljs om och om igen i samma körning.
 *
 * Schemaläggs i routes/console.php med `Schedule::call(...)`, aldrig
 * `Schedule::command(...)` — se AGENTS.md § Subprocesser i driftmiljön.
 * Klassen är medvetet fri från Artisan-beroenden av samma skäl som
 * App\Console\PrunesHelpFeedback, så att ingen av misstag schemalägger den
 * som ett kommando.
 */
class PrunesPrivacyRequests
{
    /** Antal ärenden som gallras per omgång. */
    public const BATCH_SIZE = 100;

    /**
     * Disken i config/filesystems.php. Bilagorna ligger under
     * `{ärendets ulid}/{bilagans ulid}`, så hela ärendets katalog tas bort.
     */
    private const DISK = 'privacy';

    /**
     * @return int Antal gallrade ärenden.
     */
    public function handle(): int
    {
        $cutoff = now()->subMonths((int) config('privacy.retention_months'));
        $removed = 0;
        $lastId = 0;

        while (true) {
            $candidates = DB::table('privacy_request')
                ->whereNotNull('closed_at')
                ->where('closed_at', '<', $cutoff)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::BATCH_SIZE)
                ->get(['id', 'ulid', 'account_id']);

            if ($candidates->isEmpty()) {
                break;
            }

            $spärrade = $this->heldAccounts($candidates->pluck('account_id'));

            foreach ($candidates as $kandidat) {
                $lastId = (int) $kandidat->id;

                if ($kandidat->account_id !== null && isset($spärrade[(int) $kandidat->account_id])) {
                    continue;
                }

                if ($this->prunes((int) $kandidat->id, (string) $kandidat->ulid)) {
                    $removed++;
                }
            }

            if ($candidates->count() < self::BATCH_SIZE) {
                break;
            }
        }

        Log::info("Gallrade {$removed} integritetsärenden.");

        return $removed;
    }

    /**
     * Gallrar ett ärende: filerna, bilagorna, händelserna och sist ärendet.
     *
     * Filerna tas bort före transaktionen, och ett misslyckande avbryter
     * ärendet utan att någon rad rörs. En fil som redan saknas är inte ett
     * fel — ett tidigare försök kan ha hunnit halvvägs — så katalogen
     * behöver inte finnas.
     *
     * @return bool Sant när ärendet gallrades, falskt när filerna inte gick
     *              att ta bort och raderna därför ligger kvar.
     */
    private function prunes(int $id, string $ulid): bool
    {
        if (! $this->deletesFiles($ulid)) {
            return false;
        }

        DB::transaction(function () use ($id): void {
            DB::table('privacy_request_attachment')->where('privacy_request_id', $id)->delete();
            DB::table('privacy_request_event')->where('privacy_request_id', $id)->delete();
            DB::table('privacy_request')->where('id', $id)->delete();
        });

        return true;
    }

    /**
     * Tar bort hela ärendets katalog på disken `privacy`. Disken har
     * `throw => true` och kan alltså kasta; ett fel betyder att filerna
     * ligger kvar och att raderna ska göra det också.
     */
    private function deletesFiles(string $ulid): bool
    {
        try {
            return Storage::disk(self::DISK)->deleteDirectory($ulid);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Kontona bland kandidaterna som har en gällande spärr, som en mängd
     * `id => true`.
     *
     * Frågan ställs med LegalHold::covers() och ett konto i taget — den enda
     * formuleringen av "är kontot spärrat" (issue 112). En kandidat utan
     * konto, och ett konto som inte längre finns, är aldrig spärrad och
     * hoppas över utan en fråga.
     *
     * @param  Collection<int, mixed>  $accountIds
     * @return array<int, true>
     */
    private function heldAccounts(Collection $accountIds): array
    {
        $ids = $accountIds
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $spärrade = [];

        foreach (Account::query()->whereIn('id', $ids->all())->get() as $konto) {
            if (LegalHold::covers($konto)) {
                $spärrade[$konto->id] = true;
            }
        }

        return $spärrade;
    }
}

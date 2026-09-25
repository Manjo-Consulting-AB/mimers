<?php

namespace App\Support;

use App\Models\DismissedTip;
use App\Models\User;

/**
 * Tipsen i informationsytan — vilka de är, i vilken ordning de visas, och
 * vilka den här användaren redan kryssat bort. Se [[M19 Dashboarden]] § 128
 * och [[ADR-0039 Containerns översikt]] § Konsekvenser.
 *
 * **Ordningen är bestämd och bor här.** Tipsen är en ordnad lista av nycklar
 * och ingen tabell håller dem (issuens krav 3): en tabell hade gjort
 * ordningen till data någon kunde ändra i drift, och en slumpad ordning hade
 * gett två användare olika första tips utan att någon bett om det. `KEYS` är
 * ordningen ytan bläddrar i, och den är densamma för alla.
 *
 * **Nyckeln är också strängens adress.** Rubriken och brödtexten ligger i
 * `lang/en/ui.php` under `tips.{nyckel}.title` och `tips.{nyckel}.body`
 * (issuens krav 4). Klassen bär därför ingen text — den bär bara nycklarna,
 * och en ny nyckel utan sträng är en synlig lucka i `lang/` i stället för en
 * tystnad i koden (SprakTest).
 *
 * **Listan är klassens data och därför en konstruktorparameter.** Standard är
 * `KEYS`, och en senare release som lägger till ett tips ändrar konstanten.
 * Parametern finns för att provet ska kunna ställa frågan som kravet ställer:
 * *visas ett tips som lagts till EFTER att användaren dolt allt?* — och den
 * frågan går inte att ställa mot dagens lista utan att dagens lista ändras.
 *
 * **Frågekostnaden är EN fråga per anrop.** Alla användarens dolda nycklar
 * hämtas i ett svep och jämförs i PHP; ingen fråga per tips, och ingen
 * `whereIn` mot `KEYS` som hade vuxit med listan.
 */
final class Tips
{
    /**
     * Tipsen i visningsordning. Ordningen HÄR är ordningen ytan bläddrar i —
     * ändra den inte utan att ändra vad första tipset är.
     *
     * @var list<string>
     */
    public const KEYS = ['containers', 'structure', 'schedules'];

    /**
     * @param  list<string>  $keys  Tipsen i visningsordning.
     */
    public function __construct(private readonly array $keys = self::KEYS) {}

    /**
     * Finns nyckeln bland tipsen? Grinden för `POST /tips/{key}/dismiss` —
     * en okänd nyckel är en 404 och inte en rad i `dismissed_tip`.
     */
    public function knows(string $key): bool
    {
        return in_array($key, $this->keys, true);
    }

    /**
     * Tipsen i ordning, utan de användaren redan dolt — det första är det
     * ytan visar, och resten är det hon kan bläddra till.
     *
     * Tomt svar betyder att allt är dolt, och då ritas ytan inte alls
     * (issuens *Klart när*). `array_diff` bevarar ordningen i `$this->keys`
     * och nycklarna i den första arrayen, så en ny, odold nyckel hamnar på
     * sin plats i listan och inte i slutet.
     *
     * @return list<string>
     */
    public function visibleFor(User $user): array
    {
        $dismissed = DismissedTip::query()
            ->where('user_id', $user->id)
            ->pluck('tip_key')
            ->all();

        return array_values(array_diff($this->keys, $dismissed));
    }
}

<?php

namespace App\Actions\Plan;

use App\Exceptions\Api\ApiException;
use App\Models\Account;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageCounter;
use App\Support\Plan\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use RuntimeException;

/**
 * Planen, förbrukningen och nedgraderingens pris för ETT konto — issue 66a, se
 * [[Planer och kvoter]] och [[ADR-0009 Kvoter och livscykel]].
 *
 * **Actionen är ren läsning** (Beslut 3): ingen Gate, ingen skrivning, ingen
 * rad rörd — inte ens förhandsvisningen, som beskriver ett urval utan att göra
 * det. Behörigheten prövas av anroparen, samma linje som
 * App\Actions\Item\ListItems och [[ADR-0024 Tunna controllers och actions]].
 *
 * **Siffrorna kommer ur samma formulering som gränserna prövas med.** De fyra
 * numeriska gränserna läses genom `Account::planLimit()` — exakt den väg
 * App\Support\Plan\Entitlements går — och förbrukningen ur `usage_counter`,
 * aldrig som en egen SUM (Beslut 8). En `SUM(byte_size)` här vore en andra
 * sanning vid sidan av räknaren, och de två glider isär så fort en beräkning
 * avbryts ([[Planer och kvoter]] § usage_counter). En egen formel i vyn hade
 * visat ledigt utrymme där servern säger nej.
 *
 * **Funktionsgränserna ställs som FRÅGOR, inte som uppslag** (Beslut 3): de
 * fem går genom `Entitlements::assertFeature()`, och ett kastat undantag
 * betyder nej. Att läsa `planLimit($feature) !== false` i stället hade varit en
 * andra formulering av samma regel — samma val och samma skäl som
 * App\Http\Controllers\WebhookEndpointController::featureNotice() gör.
 *
 * **`null` är obegränsat, aldrig noll** (Beslut 4): varje gräns som kan vara
 * `null` följer med som `null` hela vägen ut, och vyn ritar "obegränsat" i
 * stället för en full stapel eller ett `0`. Ett `(int) null` någonstans på
 * vägen är den enda buggen som spelar roll på den här sidan.
 *
 * **Bytena formateras här**, med `Number::fileSize()` — samma formatering som
 * App\Support\Frontend\ApiErrorTranslator gör för `*_bytes` i 60a (Beslut 4).
 * Klienten har ingen egen formatering att låna: `formatByteSize` i
 * resources/js/components/attachmentPresentation.js speglar just
 * `Number::fileSize()`, och att räkna om samma tal på två ställen är två
 * svar på samma fråga i samma vy.
 *
 * **Frågekostnaden är konstant** (Beslut 8): planen, räknarens två tal och ett
 * svep över kontots bilagor — aldrig en fråga per rad. Funktionsgränserna
 * kostar ett planuppslag var när kontot SAKNAR prenumeration, för
 * `Account::currentPlan()` faller då tillbaka på `free` med en egen fråga; fem
 * i stället för en, alltså ett konstant tal och inte ett som växer. Samma
 * uppslag memoiseras av `PlanResource::forAccount()` för de delade propsen —
 * att göra det även här hade varit en tredje plats att hålla i takt.
 */
class ReadPlanUsage
{
    /**
     * De fem funktionsgränserna — de som visas som ingår/ingår inte (Beslut 4).
     * Ordningen är nycklarnas ordning i planens `limits` och i [[Planer och
     * kvoter]] § Gränserna i MVP, och den styr radordningen i vyn: listan
     * skickas som en lista och inte som ett objekt, så att ordningen inte
     * beror på hur JSON-serialiseringen råkar sortera nycklar.
     *
     * De fyra övriga nycklarna i `limits` är numeriska och visas som
     * förbrukning; de läses i handle() och står inte här.
     *
     * @var list<string>
     */
    public const FEATURES = [
        'webhooks',
        'pdf_binder',
        'ownership_transfer',
        'loan_reminders',
        'cost_reports',
    ];

    public function __construct(private readonly Entitlements $entitlements) {}

    /**
     * Planen, förbrukningen mot dess gränser och — för ett konto över
     * gratisnivån — vad en nedgradering skulle kosta (Beslut 7).
     *
     * @return array<string, mixed>
     */
    public function handle(Account $account): array
    {
        $plan = $account->currentPlan();

        // Räknaren läses med `value()` per kolumn — ordagrant samma läsning som
        // App\Support\Plan\Entitlements gör i assertCanCreateContainer() och
        // assertStorageWithinLimit() (issue 27 § Beslut 4). Saknas raden är
        // förbrukningen noll: ett konto utan räknarrad har inga bilagor och
        // inga containers (issue 26a § Beslut 1). Två frågor och inte en, för
        // att formuleringen ska vara Entitlements' och inte en egen.
        $usedBytes = (int) UsageCounter::query()->where('account_id', $account->id)->value('storage_bytes');
        $usedContainers = (int) UsageCounter::query()->where('account_id', $account->id)->value('container_count');

        $containerLimit = $this->intLimit('containers', $plan->planLimit('containers'));
        $storageLimit = $this->intLimit('storage_bytes', $plan->planLimit('storage_bytes'));
        $maxFileLimit = $this->intLimit('max_file_bytes', $plan->planLimit('max_file_bytes'));
        $sharedLimit = $this->intLimit('shared_users_per_container', $plan->planLimit('shared_users_per_container'));

        $subscription = $account->subscription;

        return [
            'plan' => [
                'code' => $plan->code,
                'price' => $this->priceLabel($plan),
                'period' => $plan->billing_period,
            ],

            // Kontots status står överst i vyn och inte nedgrävd (Beslut 5).
            // `reason` är koden ur `account.read_only_reason` — vyn slår upp
            // meningen på den — och `graceDaysLeft` är `grace_until` minus
            // serverns nu, aldrig en omräkning av de tre månaderna: fristen
            // räknas ut av App\Actions\Plan\StartDowngrade och bara där.
            'status' => [
                'code' => $account->status,
                'reason' => $account->read_only_reason,
                'graceDaysLeft' => $this->graceDaysLeft($subscription),
            ],

            'usage' => [
                'containers' => [
                    'used' => $usedContainers,
                    'limit' => $containerLimit,
                ],
                'storage' => [
                    'usedBytes' => $usedBytes,
                    'limitBytes' => $storageLimit,
                    'usedLabel' => Number::fileSize($usedBytes),
                    'limitLabel' => $storageLimit === null ? null : Number::fileSize($storageLimit),
                    'percent' => $this->percent($usedBytes, $storageLimit),
                ],
                'maxFile' => [
                    'limitBytes' => $maxFileLimit,
                    'limitLabel' => $maxFileLimit === null ? null : Number::fileSize($maxFileLimit),
                ],
                'sharedUsersPerContainer' => [
                    'limit' => $sharedLimit,
                ],
            ],

            'features' => $this->features($account),

            'downgrade' => $this->downgrade($account, $usedBytes),
        ];
    }

    /**
     * Funktionstabellen: en rad per nyckel i FEATURES, i den ordningen.
     *
     * @return list<array{key: string, included: bool}>
     */
    private function features(Account $account): array
    {
        $features = [];

        foreach (self::FEATURES as $feature) {
            try {
                $this->entitlements->assertFeature($account, $feature);
                $included = true;
            } catch (ApiException) {
                $included = false;
            }

            $features[] = ['key' => $feature, 'included' => $included];
        }

        return $features;
    }

    /**
     * Vad en nedgradering skulle kosta, eller `null` när allt redan ryms
     * (Beslut 7). Vyn säger "allt ryms i Free" på `null` och ritar inga siffror
     * om radering.
     *
     * **Målet är gratisplanens gräns**, läst genom planuppslaget — aldrig
     * kontots nuvarande plans gräns och aldrig en konstant. Samma läsning som
     * App\Console\EnforcesDowngrades::freeStorageLimit() gör när den
     * verkställer steg 4.
     *
     * **Räkningen följer exakt den ordning steg 4 faktiskt använder**:
     * bilagorna nyast först (`created_at` fallande med `id` fallande som
     * andrasortering), summerade tills kontot ryms. Ordningen står i
     * App\Console\EnforcesDowngrades, och en förhandsvisning som sorterade
     * annorlunda vore en gissning som inte stämmer med utfallet.
     *
     * **Talet är antalet bilagor som faktiskt skulle raderas**, inte ett
     * teoretiskt minimum: räcker inte bilagorna ända fram raderas alla, precis
     * som steg 4 gör, och siffran är då antalet som finns.
     *
     * Räknaren är förbrukningens sanning — därför jämförs utfallet mot
     * `usage_counter` och inte mot summan av raderna nedan (Beslut 8). Raderna
     * beskriver urvalet; räknaren beskriver förbrukningen.
     *
     * @return array{freeStorageLabel: string, overBytes: int, overLabel: string, attachmentsToRemove: int}|null
     */
    private function downgrade(Account $account, int $usedBytes): ?array
    {
        $freeLimit = $this->intLimit(
            'storage_bytes',
            Plan::query()->where('code', 'free')->firstOrFail()->planLimit('storage_bytes'),
        );

        if ($freeLimit === null || $usedBytes <= $freeLimit) {
            return null;
        }

        $overBytes = $usedBytes - $freeLimit;
        $freed = 0;
        $attachments = 0;

        // Ett strömmande svep, inte en fråga per bilaga och inte hela listan i
        // minnet. Frågan är App\Models\UsageCounter::calculateStorageBytes()
        // formulerad för urvalet — samma join mot `stored_file`, samma
        // `deleted_at`-villkor — och `orderBy` är App\Console\EnforcesDowngrades
        // ordning, så förhandsvisningen räknar i exakt den ordning steg 4
        // raderar i.
        $rows = DB::table('attachment')
            ->join('stored_file', 'stored_file.id', '=', 'attachment.stored_file_id')
            ->where('attachment.billed_account_id', $account->id)
            ->whereNull('attachment.deleted_at')
            ->orderByDesc('attachment.created_at')
            ->orderByDesc('attachment.id')
            ->select('stored_file.byte_size')
            ->cursor();

        foreach ($rows as $row) {
            if ($freed >= $overBytes) {
                break;
            }

            $freed += (int) $row->byte_size;
            $attachments++;
        }

        return [
            'freeStorageLabel' => Number::fileSize($freeLimit),
            'overBytes' => $overBytes,
            'overLabel' => Number::fileSize($overBytes),
            'attachmentsToRemove' => $attachments,
        ];
    }

    /**
     * Hur många dagar av fristen som återstår, räknat på serverns datum
     * (Beslut 5). Negativt blir noll: en passerad frist visas som "0 dagar
     * kvar", aldrig som ett negativt tal.
     *
     * Dygnet räknas ur två tidsstämplar och inte med `diffInDays()`, vars
     * teckenkonvention skiljer sig mellan Carbon-versioner — samma beräkning
     * som resources/js/components/trashPresentation.js gör på klientsidan för
     * papperskorgen.
     *
     * Ett konto utan prenumeration har ingen `grace_until` och därmed ingen
     * frist: `null`, och vyn utelämnar raden (Beslut 5, och samma fall som
     * App\Console\EnforcesDowngrades lämnar orört).
     */
    private function graceDaysLeft(?Subscription $subscription): ?int
    {
        $graceUntil = $subscription?->grace_until;

        if ($graceUntil === null) {
            return null;
        }

        $seconds = $graceUntil->getTimestamp() - now()->getTimestamp();

        return max(0, (int) floor($seconds / 86400));
    }

    /**
     * Andelen av taket som är förbrukad, i hela procent, för stapeln i vyn.
     * `null` när taket är obegränsat — en stapel mot en gräns som inte finns
     * vore påhittad (Beslut 4).
     *
     * Klampad vid 100: ett konto över sin gräns har en full stapel, och hur
     * långt över det ligger står i talen bredvid i stället.
     */
    private function percent(int $usedBytes, ?int $limitBytes): ?int
    {
        if ($limitBytes === null || $limitBytes <= 0) {
            return null;
        }

        return min(100, (int) round($usedBytes / $limitBytes * 100));
    }

    /**
     * Planens pris som en färdig sträng. Gratisplanen säger "kostnadsfritt" i
     * stället för "0 EUR" — talet är rätt och meningen fel.
     *
     * Priset ligger i minsta valutaenhet och delas med 100: MVP:s valuta är
     * EUR, som har två decimaler. En valuta utan decimaler (JPY) skulle behöva
     * en egen division, och då är det här raden som ska ändras.
     */
    private function priceLabel(Plan $plan): string
    {
        if ((int) $plan->price_amount === 0) {
            return (string) trans('ui.plan.price_free');
        }

        return Number::currency($plan->price_amount / 100, $plan->price_currency);
    }

    /**
     * En numerisk gräns som `int` eller `null` (obegränsat).
     *
     * `Plan::planLimit()` svarar `int|bool|null` därför att de FEM
     * funktionsgränserna är booleska — för de fyra numeriska nycklarna är
     * `true`/`false` en trasig planrad. Den kastar hellre än visar något:
     * att göra en boolean till `null` vore att rita "obegränsat" för ett konto
     * som i själva verket nekas på en gräns, och det är precis den riktning
     * felet inte får gå (Beslut 4). Samma hållning som
     * `Plan::planLimit()`s eget undantag för en okänd nyckel och
     * `Account::currentPlan()`s för en saknad gratisplan: ett trasigt system
     * ska märkas, inte tigas ihjäl.
     *
     * `null` är obegränsat och passerar oförändrat.
     */
    private function intLimit(string $key, int|bool|null $limit): ?int
    {
        if (is_int($limit)) {
            return $limit;
        }

        if ($limit === null) {
            return null;
        }

        throw new RuntimeException("Plangränsen [{$key}] är inte ett tal.");
    }
}

<?php

namespace App\Actions\Voucher;

use App\Models\Plan;
use App\Models\Voucher;
use Illuminate\Support\Carbon;

/**
 * Skapa en voucher och lämna tillbaka koden — se [[ADR-0055 Inbjudningskoder
 * och stängd registrering]] § 5 och § 7.
 *
 * Koden är `XXXX-XXXX-XXXX` ur Crockfords base32: tolv tecken, alltså 60
 * bitar slump ur `random_int` (ADR-0055 § 5). Den visas EN gång — i
 * returvärdet härifrån, som `voucher:create` skriver ut — och därefter finns
 * bara hashen. Koden går inte att läsa ut igen; tappas den skapas en ny.
 *
 * Bara hashen sparas: `code_hash` är SHA-256 av `Voucher::normalize()` av
 * koden, samma grepp som inbjudningarnas `token_hash` (ADR-0055 § 4). Att
 * normalisera FÖRE hashningen är det som gör `o` till `0` i uppslaget.
 *
 * `plan_id`, `code_hash` och `used_count` sätts explicit och inte via
 * massildelning: de står utanför Voucher::#[Fillable] med flit, se modellens
 * docblock, och samma grepp som
 * App\Actions\OwnershipTransfer\AcceptOwnershipTransfer::grantProTime().
 *
 * Ingen transaktion: actionen skriver en enda rad. Anroparen är
 * `voucher:create`, som körs för hand över SSH (ADR-0055 § 7).
 */
class GenerateVoucher
{
    /**
     * Crockfords base32 utan `I`, `L`, `O` och `U` — de tre första för att
     * de förväxlas med `1` och `0` när koden skrivs av för hand, det sista
     * för att bokstaveringsalfabetet hoppar över det. Ordningen är
     * alfabetets, alltså är varje tecken ett värde 0–31 och tolv tecken
     * 60 bitar.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * Tolv tecken i tre grupper om fyra, se ADR-0055 § 5.
     */
    private const CODE_LENGTH = 12;

    private const GROUP_SIZE = 4;

    /**
     * @param  string  $planCode  Planens `code`, t.ex. `pro`.
     * @param  int  $days  Hur länge planen gäller från inlösen.
     * @param  int  $uses  Hur många konton som får lösa in koden.
     * @param  bool  $grantsRegistration  Släpper koden in i läget
     *                                    `invite_only`?
     * @param  string|null  $label  Vem eller vad koden gavs till.
     * @param  Carbon|null  $expiresAt  Sista tidpunkten koden kan lösas in.
     * @return array{voucher: Voucher, code: string} Koden i klartext —
     *                                               enda gången den finns.
     */
    public function handle(
        string $planCode,
        int $days,
        int $uses,
        bool $grantsRegistration,
        ?string $label,
        ?Carbon $expiresAt,
    ): array {
        $plan = Plan::query()->where('code', $planCode)->firstOrFail();

        $code = $this->generateCode();

        $voucher = new Voucher([
            'label' => $label,
            'duration_days' => $days,
            'max_uses' => $uses,
            'grants_registration' => $grantsRegistration,
            'expires_at' => $expiresAt,
        ]);

        $voucher->code_hash = hash('sha256', Voucher::normalize($code));
        $voucher->plan_id = $plan->getKey();
        $voucher->used_count = 0;

        $voucher->save();

        return ['voucher' => $voucher, 'code' => $code];
    }

    /**
     * Tolv tecken ur alfabetet, grupperade fyra och fyra. `random_int` och
     * aldrig `rand`/`mt_rand`: koden är en hemlighet som släpper in i
     * systemet och ger Pro, och de två senare är förutsägbara.
     */
    private function generateCode(): string
    {
        $tecken = '';

        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $tecken .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return implode('-', str_split($tecken, self::GROUP_SIZE));
    }
}

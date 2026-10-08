<?php

namespace App\Actions\Auth;

use App\Models\Invitation;
use App\Models\Voucher;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Släpper in en registrering eller nekar den — den ENDA platsen där
 * [[ADR-0055 Inbjudningskoder och stängd registrering]] § 2 och § 3
 * formuleras. Webben (#789) och API:et (#790) anropar samma action, så de
 * två ytorna inte kan glida isär i vilka som släpps in.
 *
 * Läget kommer ur `config('konton.registration')` (ADR-0055 § 1):
 *
 * - `open`: koden är frivillig. Utan kod släpps alla in. En giltig kod ger
 *   sin plan; en ogiltig NEKAR registreringen, så att ingen tror att hon
 *   fått Pro när hon inte fått det (ADR-0055 § 2).
 * - `invite_only`: en giltig kod krävs. Den måste dessutom bära
 *   `grants_registration` — en kod som bara ger Pro är ingen biljett in.
 *   Finns ingen sådan kod går en utestående containerinbjudan till samma
 *   adress bra i stället, och ger Free (ADR-0055 § 3).
 *
 * Inbjudan prövas med sin TOKEN, inte bara med adressen: registreringen
 * kräver ingen verifierad adress, så en adress bevisar inte att man fått
 * mejlet (ADR-0055 § 3). Adressen måste ändå vara densamma, skiftlägesokäns-
 * ligt. Inbjudan ACCEPTERAS inte här — det sker som i dag, i
 * App\Actions\Invitation\AcceptInvitation.
 *
 * Returvärdet är vouchern att lösa in, eller `null` när registreringen
 * släpps in utan kod. Anroparen löser in den med App\Actions\Voucher\
 * RedeemVoucher inne i transaktionen som skapar kontot; actionen här varken
 * skriver eller låser något.
 *
 * Felen följer ADR-0055 § 8: en kod som inte släpper in ger samma svar som
 * en ogiltig kod, och i `invite_only` utan vare sig kod eller inbjudan är
 * fältet obligatoriskt. Svaren skiljer inte fallen åt, så det går inte att
 * pröva sig fram till vilka koder som finns.
 */
class AdmitRegistration
{
    /**
     * @return Voucher|null Vouchern att lösa in, eller null när
     *                      registreringen släpps in utan kod.
     *
     * @throws ValidationException på `voucher_code`.
     */
    public function handle(string $email, ?string $voucherCode, ?string $invitationToken): ?Voucher
    {
        $voucher = $voucherCode === null ? null : Voucher::findByCode($voucherCode);
        $open = $this->registrationIsOpen();

        if ($this->admits($voucher, $open)) {
            return $voucher;
        }

        if ($open) {
            if ($voucherCode === null) {
                return null;
            }

            throw $this->notRedeemable();
        }

        if ($voucherCode === null && $this->hasOutstandingInvitation($email, $invitationToken)) {
            return null;
        }

        throw $this->missingCode();
    }

    /**
     * Släpper koden in? I `open` räcker det att den går att lösa in; i
     * `invite_only` måste den dessutom bära `grants_registration` — annars är
     * den en Pro-kod och ingen biljett in i betan.
     */
    private function admits(?Voucher $voucher, bool $open): bool
    {
        if ($voucher === null || ! $voucher->isRedeemable()) {
            return false;
        }

        return $open || $voucher->grants_registration;
    }

    /**
     * Finns en utestående inbjudan till samma adress, med den här token?
     *
     * `token_hash` är SHA-256 av token, precis som i
     * App\Support\Auth\MagicLinkBroker och App\Models\Invitation — klartexten
     * finns bara i mejlets länk. Adressen jämförs skiftlägesokänsligt, och
     * jämförelsen görs i SQL med `lower()` eftersom sqlite (test) är
     * skiftlägeskänsligt där MariaDB är skiftlägesokänsligt via sin
     * collation — utan den vore provet grönt i produktion och rött i sviten,
     * eller tvärtom.
     */
    private function hasOutstandingInvitation(string $email, ?string $invitationToken): bool
    {
        if ($invitationToken === null) {
            return false;
        }

        return Invitation::query()
            ->outstanding()
            ->where('token_hash', hash('sha256', $invitationToken))
            ->whereRaw('lower(email) = ?', [Str::lower($email)])
            ->exists();
    }

    private function registrationIsOpen(): bool
    {
        return config('konton.registration') === 'open';
    }

    /**
     * @throws ValidationException
     */
    private function notRedeemable(): ValidationException
    {
        return ValidationException::withMessages([
            'voucher_code' => __('validation.redeemable_voucher'),
        ]);
    }

    /**
     * Fältet är obligatoriskt: `validation.required` på `voucher_code`
     * (ADR-0055 § 8).
     *
     * Undantaget byggs ur en riktig validator och inte med
     * ValidationException::withMessages(), fastän det är husets vanliga grepp.
     * Två skäl, båda i den här issuen:
     *
     * - Fältkoden i API-höljet härleds ur den felade REGELN
     *   (App\Support\Api\ValidationErrorMapper::ruleCode()), så
     *   `withMessages` hade gett `validation.invalid` i stället för
     *   `validation.required` — och #790 lovar `validation.required`.
     * - Meddelandets platshållare byts ut av validatorn, så webben visar
     *   "The voucher code field is required." i stället för "The :attribute
     *   field is required." (#789 visar felet i formuläret).
     *
     * @throws ValidationException
     */
    private function missingCode(): ValidationException
    {
        $validator = Validator::make(
            ['voucher_code' => null],
            ['voucher_code' => ['required']],
        );

        // Kör valideringen så att `failed()` och meddelandena fylls i; den
        // misslyckas alltid, eftersom värdet med flit är null. `validate()`
        // hade kastat i stället för att lämna tillbaka undantaget.
        $validator->fails();

        return new ValidationException($validator);
    }
}

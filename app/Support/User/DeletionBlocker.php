<?php

namespace App\Support\User;

use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * Ett hinder för att radera en person — se [[ADR-0045 Radering av konto och
 * person]] § Beslut 3 och App\Actions\User\DeleteUser.
 *
 * **Koden är maskinläsbar och stabil**, i samma form som API:ets felkoder
 * (AGENTS.md § Felformat i API:et): domän först, sedan vad som gäller.
 * App\Actions\User\DeleteUser svarar med en lista av de här, och issue 145
 * översätter koden till en mening och visar `$data` som "det den gäller" —
 * koden stavas på ett ställe och aldrig i en vy.
 *
 * Namnen på de två sista leden är desamma som `reason` i de
 * `account.deletion_blocked`-rader App\Console\DeletesDormantAccounts skriver
 * (`shared_container`, `legal_hold`): samma hinder ska heta samma sak i
 * loggen och i ytan. `sole_owner` finns bara här — livscykeln raderar ett
 * konto och frågar aldrig efter en ensam ägare.
 *
 * **`$data` bär bara identifierare, aldrig personuppgifter**: konton och
 * containrar som ULID. Ingenting i den hamnar i en logg. Den rättsliga
 * spärren är undantaget som bekräftar regeln — den bär ingen `$data` alls,
 * se `legalHold()`.
 */
final readonly class DeletionBlocker
{
    /**
     * Personen är den enda `owner` i ett konto som har andra medlemmar.
     * Kontot skulle lämnas utan ägare (ADR-0045 § Beslut 3).
     */
    public const CODE_SOLE_OWNER = 'user.deletion_blocked.sole_owner';

    /**
     * Ett konto där personen är enda medlem äger en container med aktiva
     * medlemmar. Samma spärr som i livscykeln (29b § Beslut 3–4): ägarskapet
     * ska erbjudas dem först, och vägen ut är ägarbytet.
     */
    public const CODE_SHARED_CONTAINER = 'user.deletion_blocked.shared_container';

    /**
     * En rättslig spärr täcker ett av personens konton ([[ADR-0043 Tre
     * loggar]] § Den rättsliga spärren). Spärren gäller kontots innehåll och
     * stoppar hela raderingen.
     *
     * **Den bär bara koden — ingen `$data`.** Till skillnad från de två andra
     * spärrarna får ingenting här peka ut vilket konto eller vilket innehåll
     * spärren gäller: ytan (issue 145) visar ett neutralt besked om att
     * raderingen inte kan göras just nu, och en tom `$data` gör det omöjligt
     * att avslöja spärren av misstag. Att veta att ett konto är spärrat är i
     * sig en uppgift om en pågående utredning.
     */
    public const CODE_LEGAL_HOLD = 'user.deletion_blocked.legal_hold';

    /**
     * @param  array<string, mixed>  $data  Det spärren gäller.
     */
    private function __construct(
        public string $code,
        public array $data,
    ) {}

    public static function soleOwner(Account $account): self
    {
        return new self(self::CODE_SOLE_OWNER, ['account' => $account->ulid]);
    }

    /**
     * @param  Collection<int, string>  $containers  Containrarnas ULID.
     */
    public static function sharedContainer(Account $account, Collection $containers): self
    {
        return new self(self::CODE_SHARED_CONTAINER, [
            'account' => $account->ulid,
            'containers' => $containers->values()->all(),
        ]);
    }

    public static function legalHold(): self
    {
        return new self(self::CODE_LEGAL_HOLD, []);
    }
}

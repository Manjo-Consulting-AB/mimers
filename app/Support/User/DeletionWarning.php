<?php

namespace App\Support\User;

use App\Models\Account;
use Illuminate\Support\Collection;

/**
 * En upplysning inför en personradering — något som inte spärrar men som
 * personen måste bekräfta innan raderingen får göras, se [[ADR-0045 Radering
 * av konto och person]] § Uppföljning 2026-11-14 och
 * App\Actions\User\DeleteUser.
 *
 * **Skillnaden mot App\Support\User\DeletionBlocker.** En spärr säger att
 * raderingen inte GÅR att göra; en upplysning säger att den går, men att den
 * får konsekvenser personen ska känna till. Därför kastar de två olika
 * undantag, och ytan ritar dem i två olika rutor.
 *
 * **Koden är maskinläsbar och stabil**, i samma form som spärrarnas: domän
 * först, sedan vad som gäller. Ytan väljer mening på kodens sista led och
 * stavar aldrig koden själv.
 *
 * **`$data` bär bara identifierare, aldrig personuppgifter** — kontot,
 * containrarna och medlemmarna som ULID. Namnen slås upp i
 * App\Http\Controllers\Settings\SecurityController, som gör samma sak för
 * spärrarna.
 */
final readonly class DeletionWarning
{
    /**
     * Ett konto där personen är enda medlem äger containrar med aktiva
     * medlemmar. Containrarna raderas med kontot, och medlemmarna förlorar
     * åtkomsten — personen ska ha fått veta det och bekräftat det.
     */
    public const CODE_SHARED_CONTAINER = 'user.deletion_warning.shared_container';

    /**
     * @param  array<string, mixed>  $data  Det upplysningen gäller.
     */
    private function __construct(
        public string $code,
        public array $data,
    ) {}

    /**
     * @param  Collection<int, string>  $containers  Containrarnas ULID.
     * @param  Collection<int, string>  $members  Medlemmarnas ULID.
     */
    public static function sharedContainer(Account $account, Collection $containers, Collection $members): self
    {
        return new self(self::CODE_SHARED_CONTAINER, [
            'account' => $account->ulid,
            'containers' => $containers->values()->all(),
            'members' => $members->values()->all(),
        ]);
    }
}

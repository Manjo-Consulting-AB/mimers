<?php

namespace App\Actions\Agent;

use App\Models\Account;
use App\Models\User;

/**
 * Personkontot en agents anslutning hör till, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 4 och [[Konton och åtkomst]] § agent.
 *
 * **Regeln är ActingAccounts, utan en container.** En agent är personens egen,
 * och förbrukningen belastar personkontot precis som en uppladdning personen
 * gör själv. Flera personkonton är en patologi; lägst `id` vinner,
 * deterministiskt — samma förval som App\Support\Account\ActingAccount och
 * App\Actions\Cost\CreateCostEntry::attributedAccountId().
 *
 * Saknas ett personkonto faller vi tillbaka på användarens första konto, av
 * samma skäl som ActingAccount: handlingen måste ha ett konto. Saknas även
 * det är användaren trasig och `firstOrFail()` ger 404 i stället för en
 * påhittad ägare.
 */
final class ResolveAgentAccount
{
    public function handle(User $user): Account
    {
        return $user->accounts()
            ->where('account.type', 'personal')
            ->orderBy('account.id')
            ->first()
            ?? $user->accounts()->orderBy('account.id')->firstOrFail();
    }
}

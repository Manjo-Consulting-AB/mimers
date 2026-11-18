<?php

namespace App\Support\Mcp;

/**
 * Domänerna en agent aldrig får röra, se [[ADR-0087 MCP-server för agenter]]
 * Riktning 7 och issue 404 § 3.
 *
 * Förbuden gäller oavsett vilka rättigheter användaren eller ägaren ger, och
 * de efterlevs på två sätt: det finns inga verktyg i de här domänerna, och
 * `ForbudTest` fäller ett verktyg vars namn eller action hör hit. Regeln
 * ligger här och inte i testet, så att det är samma lista som prövas och som
 * beskriver förbudet.
 *
 * Förbjudet: att tömma eller rensa papperskorgen, kontots inställningar, plan
 * och betalning, inbjudningar, åtkomst och agenttak, containerns bild och
 * profilbilden, webhooks, kalenderflödet, ägarbyte, agenters rättigheter och
 * tokens, och export.
 */
final class ForbiddenDomains
{
    /**
     * Ord i ett verktygsnamn (delat på `_`) som hör till en förbjuden domän.
     * Namnen följer `domän_verb` på engelska, så ett helt ord räcker —
     * delsträngar vore för trubbigt (`containers` innehåller `container`).
     *
     * @var list<string>
     */
    public const TOOL_WORDS = [
        'trash', 'purge',
        'account', 'plan', 'billing', 'payment', 'subscription',
        'invitation', 'invite', 'member', 'access', 'accesses', 'grant', 'grants', 'ceiling',
        'avatar', 'cover',
        'webhook', 'webhooks', 'calendar', 'feed', 'feeds',
        'transfer', 'transfers', 'ownership',
        'token', 'tokens', 'disconnect', 'settings',
        'export', 'exports',
    ];

    /**
     * Actionklasser (basnamn) som hör till en förbjuden domän — de actions
     * som verktygen aldrig får anropa.
     *
     * @var list<string>
     */
    public const ACTION_CLASSES = [
        // Papperskorgen: tömma och rensa. (Radering och återställning är
        // issue 406 och omfattas inte av det här förbudet.)
        'EmptyTrash', 'PurgeContent', 'PurgeContainer', 'PurgeAttachment',
        // Kontot, planen och betalningen.
        'DeleteAccount', 'RequestEmailChange', 'ConfirmEmailChange',
        'RequestPasswordChange', 'ConfirmPasswordChange',
        'StartDowngrade', 'GrantInternalPro',
        // Inbjudningar.
        'CreateInvitation', 'RevokeInvitation', 'AcceptInvitation', 'RejectInvitation',
        // Åtkomst och agenttak.
        'GrantContainerAccess', 'UpdateContainerAccess', 'RevokeContainerAccess',
        'UpdateAgentAccountGrants', 'SetMemberAgentLevel',
        'EnableAgentInContainer', 'DisableAgentInContainer',
        // Bilder som rensas direkt.
        'SetContainerCover', 'RemoveContainerCover', 'SetContainerCoverFocus',
        'SetUserAvatar', 'RemoveUserAvatar', 'SetUserAvatarCrop',
        // Kalenderflödet.
        'CreateCalendarFeed', 'RevokeCalendarFeed',
        // Ägarbytet.
        'OfferOwnershipTransfer', 'AcceptOwnershipTransfer',
        'RejectOwnershipTransfer', 'RevokeOwnershipTransfer',
        // Agentens egna rättigheter och tokens.
        'DisconnectAgent', 'RevokeAgentTokens',
    ];

    /**
     * Ord i en actionklass som alltid hör till en förbjuden domän, även när
     * klassen är ny och inte står i listan ovan.
     *
     * @var list<string>
     */
    public const ACTION_WORDS = ['Export', 'Webhook'];

    /**
     * Hör verktyget till en förbjuden domän? Svaret är det som avslöjade det
     * — verktygets namn eller actionklassen — eller null.
     *
     * @param  list<class-string|string>  $actions
     */
    public static function offence(string $toolName, array $actions): ?string
    {
        foreach (explode('_', $toolName) as $word) {
            if (in_array($word, self::TOOL_WORDS, true)) {
                return $toolName;
            }
        }

        foreach ($actions as $action) {
            $base = class_basename($action);

            if (in_array($base, self::ACTION_CLASSES, true)) {
                return $action;
            }

            foreach (self::ACTION_WORDS as $word) {
                if (str_contains($base, $word)) {
                    return $action;
                }
            }
        }

        return null;
    }
}

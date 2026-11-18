<?php

namespace App\Actions\Agent;

use RuntimeException;

/**
 * Anslutningen avvisades innan den hann bli en rad — kontot har inte Pro, taket
 * är nått, eller den agent användaren ville ersätta är inte hennes.
 *
 * Skälet bärs som en sträng i stället för ett undantag per fall: anroparen
 * översätter det till ett svar (`403` respektive ett val om), och ett eget
 * undantag för varje gren hade gjort `catch`-listan till en tredje kopia av
 * reglerna i App\Actions\Agent\InspectAgentConnection.
 */
class AgentConnectionRefusedException extends RuntimeException
{
    public const REASON_PLAN = 'plan';

    public const REASON_LIMIT = 'limit';

    public const REASON_REPLACEMENT = 'replacement';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("Agentanslutningen avvisades: {$reason}.");
    }
}

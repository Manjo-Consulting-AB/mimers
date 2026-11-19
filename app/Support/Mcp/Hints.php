<?php

namespace App\Support\Mcp;

/**
 * Tabellen över `hint` i MCP:s felsvar, se [[ADR-0087 MCP-server för
 * agenter]] Riktning 18. En fast engelsk mening per kod som säger agenten
 * vad den ska göra härnäst. Den översätts aldrig, visas aldrig för en
 * människa och finns bara i MCP-svaren — REST-API:et har ingen `message`.
 *
 * Saknas koden här utelämnas `hint`; en kod utan tabellpost är inget fel.
 */
final class Hints
{
    public const EMPTY_CONTAINERS = 'No containers are available to this agent. This does not mean Mimers is empty: the user enables the agent on /settings/agents and in each container\'s settings.';

    private const TABLE = [
        'agent.paused' => 'This agent is paused by the user. Stop and tell the user to resume it on /settings/agents.',
        'auth.forbidden' => 'The account that owns this container is read-only. Reading still works; tell the user that writing returns when the account is paid for again.',
        'agent.forbidden' => 'This agent is never allowed to do this. Ask the user to do it in the app.',
        'agent.move_widens_access' => 'This move would give more people access. Ask the user to make it in the app.',
        'agent.copy_widens_access' => 'You have read content that some people who can see this target cannot. Ask the user to make this change in the app, or search with the container filter before writing here.',
        'agent.rate_limited' => 'Too many requests. Wait retry_after_seconds before calling again.',
        'agent.delete_limit' => 'The agent has reached its limit for deletions. Stop deleting and tell the user.',
        'plan.mcp_unavailable' => 'The user\'s plan does not include MCP access. Tell the user that Pro or Pro+ is required.',
        'resource.not_found' => 'No such resource, or this agent cannot reach it. Check the ULID, or ask the user whether the agent is enabled there.',
        'validation.failed' => 'One or more arguments are invalid. See data.fields and call again with corrected values.',
        'server.error' => 'The server failed. Try again later; do not retry in a loop.',
    ];

    public static function for(string $code): ?string
    {
        return self::TABLE[$code] ?? null;
    }
}

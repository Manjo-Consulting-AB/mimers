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
    public const EMPTY_CONTAINERS = 'No containers are available to this agent. This does not mean Mimers is empty: the user enables the agent on /settings/agents and in each container\'s settings. An empty list does not rule out the user\'s inbox: inbox_get reaches it when the user has switched this agent on there.';

    private const TABLE = [
        'agent.paused' => 'This agent is paused by the user. Stop and tell the user to resume it on /settings/agents.',
        'agent.forbidden' => 'This agent is never allowed to do this. Ask the user to do it in the app.',
        'auth.forbidden' => 'The account is temporarily locked for writing, not a permission this agent lacks. Reading still works. Tell the user to resolve the account\'s billing or plan; do not retry.',
        'agent.move_widens_access' => 'This move would give more people access. Ask the user to make it in the app.',
        'agent.copy_widens_access' => 'You have read content that some people who can see this target cannot. Ask the user to make this change in the app, or search with the container filter before writing here.',
        'inbox.not_enabled' => 'The user has not switched this agent on in their inbox. Ask the user to do it on /settings/agents.',
        'agent.rate_limited' => 'Too many requests. Wait retry_after_seconds before calling again.',
        'agent.delete_limit' => 'The agent has reached its limit for deletions. Stop deleting and tell the user.',
        'agent.create_containers_not_allowed' => 'The user has not let this agent create containers. Ask the user to allow it on /settings/agents.',
        'plan.mcp_unavailable' => 'The user\'s plan does not include MCP access. Tell the user that Pro or Pro+ is required.',
        'resource.not_found' => 'No such resource, or this agent cannot reach it. Check the ULID, or ask the user whether the agent is enabled there.',
        'quota.containers_exceeded' => 'The account has reached its container limit. Tell the user, or use an existing container.',
        'field.limit_reached' => 'The container or item has as many fields as it allows. Tell the user; do not retry.',
        'field.label_taken' => 'A field with that label already exists on this item. Choose another label.',
        'field.type_locked' => 'The type cannot change because the field already has values. Clear them first, or keep the type.',
        'field.not_applicable' => 'That field does not belong to this item. Use a field from fields_list for the item\'s container or the item itself.',
        'template.max_categories_exceeded' => 'The template has more categories than one container may hold. Tell the user; nothing was changed.',
        'template.limit_reached' => 'The account has as many templates as it allows. Tell the user; an agent cannot save templates.',
        'validation.failed' => 'One or more arguments are invalid. See data.fields and call again with corrected values.',
        'server.error' => 'The server failed. Try again later; do not retry in a loop.',
    ];

    public static function for(string $code): ?string
    {
        return self::TABLE[$code] ?? null;
    }
}

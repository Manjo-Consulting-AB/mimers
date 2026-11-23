# AI agents

## Can I use Mimers with Claude or another AI assistant?

Yes. Mimers supports MCP (Model Context Protocol), which allows compatible AI assistants to access and work with your Mimers data.

To connect an assistant, tell it to connect to:

`https://mimers.app/mcp`

The assistant handles authentication through its connection to Mimers.

Connecting AI agents is available with Pro and Pro+.

<!-- video -->

## How do I control what an AI agent can access?

You control access at two levels:

**1. Account-level permissions**

First, you define the maximum permission level an agent can have on your account.

**2. Container-level access**

You then enable the agent separately for each container you want it to access. For each container, you can grant the agent any permission level up to the maximum set at account level.

You can give an agent less access in a particular container, but never more than its account-level limit.

An agent can never have higher permissions than you have yourself.

## Can other people's AI agents access a shared container?

As the container owner, you can control whether people you've invited are allowed to use their AI agents in your container.

You can also set the maximum permission level their agents can have.

An invited person's agent can never have more access than that person has themselves, and you can choose to give it less.

## What can an AI agent do in my containers?

An agent can work with your data within the permissions you've granted it.

It can never exceed your own access rights or the limits you've configured for that agent.

Agents cannot:

- Manage account-level settings.
- Change permissions or invite other users.
- Transfer ownership of containers.
- Empty the trash.

## How do I stop an AI agent?

You can pause an agent or disconnect it under **Settings → Agents**.

When you disconnect an agent, its permissions are revoked and its access keys stop working immediately.

## Can I see what an AI agent has done?

Yes. Open **History** to see what has been changed, when it happened and which agent performed the action.

This gives you an overview of the activity carried out by your connected agents.

## What happens if an AI agent changes or deletes something by mistake?

Mimers has safeguards to help you recover from mistakes.

**Item data:** Mimers keeps previous versions of your item data. By default, the three most recent versions are stored, but you can choose to keep anywhere from 0 to 20 versions. You can restore an earlier version if an agent or user makes an unwanted change.

**Attachments:** Files cannot be overwritten by an agent or a user. If an attachment is accidentally deleted, it can always be restored from the trash.

AI agents are never allowed to empty the trash, so they cannot permanently delete its contents.

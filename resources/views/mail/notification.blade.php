<x-mail::message>
@php($key = str_replace('.', '_', $type))
@php($lineKey = isset($payload['reason']) ? "notiser.{$key}.line_{$payload['reason']}" : "notiser.{$key}.line")
@php($lineKey = $type === 'container.content_moving' && ! isset($payload['moves_on']) ? "notiser.{$key}.line_no_date" : $lineKey)
{{-- Issue 1457 · varningen till det nedgraderade kontot saknar datum när
     kontot inte har någon frist; då väljer raden varianten utan datum. --}}
@php($lineKey = $type === 'account.downgrade_warning' && ! isset($payload['grace_until']) ? "notiser.{$key}.line_no_date" : $lineKey)
{{-- Issue 406 · en raderad container bär sitt innehåll med sig, men taket
     räknade den som en radering — då väljer raden varianten som säger hur
     många items som följde med. --}}
@php($lineKey = $type === 'agent.deleted' && ($payload['container_items'] ?? 0) > 0 ? "notiser.{$key}.line_containers" : $lineKey)
{{-- Knappen går till papperskorgen när det som raderats kan återställas där
     (issue 406 § 4); de andra typerna behåller appens startsida. --}}
@php($actionUrl = match ($type) {
    'agent.connected', 'agent.access_lost' => url('/settings/agents'),
    'agent.deleted' => url('/trash'),
    'account.downgrade_warning' => url('/settings/plan'),
    default => config('app.url'),
})

# {{ trans("notiser.{$key}.greeting") }}

@php($replace = $type === 'container.content_moving' && isset($payload['bytes']) ? [...$payload, 'bytes' => \Illuminate\Support\Number::fileSize((int) $payload['bytes'], 1)] : $payload)

{{ $type === 'agent.deleted' ? trans_choice($lineKey, (int) ($payload['total'] ?? 0), $replace) : trans($lineKey, $replace) }}

{{-- Issue 1457 · meningen om MCP står bara med när kontot har en giltig
     agentanslutning att förlora ([[ADR-0087 MCP-server för agenter]]
     Riktning 11). `has_agents` räknas av generatorn. --}}
@if ($type === 'account.downgrade_warning' && ($payload['has_agents'] ?? false))
{{ trans('notiser.account_downgrade_warning.mcp') }}
@endif

<x-mail::button :url="$actionUrl">
{{ trans("notiser.{$key}.action") }}
</x-mail::button>

{{-- Issue 400 · den signerade bortkopplingen. Länken räknas fram vid
     renderingen och gäller i sju dagar; den finns för att en kapad session
     ska kunna stängas av utan inloggning ([[ADR-0087 MCP-server för agenter]]
     Riktning 12). Bara agentens egen ULID står i den — aldrig ett token. --}}
@if ($type === 'agent.connected' && isset($payload['agent_ulid']))
@php($disconnectUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('mcp.agents.disconnect', now()->addDays(7), ['agent' => $payload['agent_ulid']]))

{{ trans('notiser.agent_connected.disconnect') }}

[{{ trans('notiser.agent_connected.disconnect_link') }}]({{ $disconnectUrl }})
@endif

@if ($unsubscribeUrl !== null)
<x-slot:subcopy>
@php($link = '['.trans('notiser.unsubscribe.link').']('.$unsubscribeUrl.')')
{{ trans('notiser.unsubscribe.footer', ['link' => $link]) }}
</x-slot:subcopy>
@endif
</x-mail::message>

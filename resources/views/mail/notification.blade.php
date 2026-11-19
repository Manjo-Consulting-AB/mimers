<x-mail::message>
@php($key = str_replace('.', '_', $type))
@php($lineKey = isset($payload['reason']) ? "notiser.{$key}.line_{$payload['reason']}" : "notiser.{$key}.line")
@php($lineKey = $type === 'container.content_moving' && ! isset($payload['moves_on']) ? "notiser.{$key}.line_no_date" : $lineKey)
{{-- Issue 406 · en raderad container bär sitt innehåll med sig, men taket
     räknade den som en radering — då väljer raden varianten som säger hur
     många items som följde med. --}}
@php($lineKey = $type === 'agent.deleted' && ($payload['container_items'] ?? 0) > 0 ? "notiser.{$key}.line_containers" : $lineKey)
{{-- Knappen går till papperskorgen när det som raderats kan återställas där
     (issue 406 § 4); de andra typerna behåller appens startsida. --}}
@php($actionUrl = match ($type) {
    'agent.connected', 'agent.access_lost' => url('/settings/agents'),
    'agent.deleted' => url('/trash'),
    default => config('app.url'),
})

# {{ trans("notiser.{$key}.greeting") }}

@php($replace = $type === 'container.content_moving' && isset($payload['bytes']) ? [...$payload, 'bytes' => \Illuminate\Support\Number::fileSize((int) $payload['bytes'], 1)] : $payload)

{{ trans($lineKey, $replace) }}

{{-- Issue 409 · varningen vid nedgradering nämner MCP, men bara när kontot
     har en agent (StartDowngrade sätter `mcp` i payloaden). --}}
@if ($type === 'container.content_moving' && ! empty($payload['mcp']))
{{ trans('notiser.container_content_moving.mcp_line', ['account' => $payload['account'] ?? '']) }}
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

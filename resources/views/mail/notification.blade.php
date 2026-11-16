<x-mail::message>
@php($key = str_replace('.', '_', $type))
@php($lineKey = isset($payload['reason']) ? "notiser.{$key}.line_{$payload['reason']}" : "notiser.{$key}.line")
@php($lineKey = $type === 'container.content_moving' && ! isset($payload['moves_on']) ? "notiser.{$key}.line_no_date" : $lineKey)

# {{ trans("notiser.{$key}.greeting") }}

@php($replace = $type === 'container.content_moving' && isset($payload['bytes']) ? [...$payload, 'bytes' => \Illuminate\Support\Number::fileSize((int) $payload['bytes'], 1)] : $payload)

{{ trans($lineKey, $replace) }}

<x-mail::button :url="config('app.url')">
{{ trans("notiser.{$key}.action") }}
</x-mail::button>

<x-slot:subcopy>
@php($link = '['.trans('notiser.unsubscribe.link').']('.$unsubscribeUrl.')')
{{ trans('notiser.unsubscribe.footer', ['link' => $link]) }}
</x-slot:subcopy>
</x-mail::message>

<x-mail::message>
@php($key = str_replace('.', '_', $type))
@php($lineKey = isset($payload['reason']) ? "notiser.{$key}.line_{$payload['reason']}" : "notiser.{$key}.line")

# {{ trans("notiser.{$key}.greeting") }}

{{ trans($lineKey, $payload) }}

<x-mail::button :url="config('app.url')">
{{ trans("notiser.{$key}.action") }}
</x-mail::button>

<x-slot:subcopy>
@php($link = '['.trans('notiser.unsubscribe.link').']('.$unsubscribeUrl.')')
{{ trans('notiser.unsubscribe.footer', ['link' => $link]) }}
</x-slot:subcopy>
</x-mail::message>

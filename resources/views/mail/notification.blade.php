<x-mail::message>
@php($key = str_replace('.', '_', $type))

# {{ trans("notiser.{$key}.greeting") }}

{{ trans("notiser.{$key}.line", $payload) }}

<x-mail::button :url="config('app.url')">
{{ trans("notiser.{$key}.action") }}
</x-mail::button>
</x-mail::message>

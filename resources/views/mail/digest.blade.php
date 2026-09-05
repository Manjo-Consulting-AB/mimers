<x-mail::message>
# {{ trans('notiser.digest.greeting') }}

{{ trans('notiser.digest.intro') }}

@foreach ($items as $item)
@php($key = str_replace('.', '_', $item['type']))
- {{ trans("notiser.{$key}.line", $item['payload']) }}
@endforeach

@if ($moreCount > 0)
{{ trans('notiser.digest.more', ['count' => $moreCount]) }}
@endif
</x-mail::message>

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('export.binder._style')</head>
<body>
    <h1 style="font-size: 28pt; margin-top: 60pt;">{{ $container->name }}</h1>
    <p class="muted">{{ __('export.binder_title') }}</p>

    @if ($image !== null)
        <p><img src="{{ $image }}" style="max-width: 100%; max-height: 260pt;"></p>
    @endif

    @if (filled($container->description))
        <p>{{ $container->description }}</p>
    @endif

    <table>
        <tr><th>{{ __('export.owner') }}</th><td>{{ $owner }}</td></tr>
        <tr><th>{{ __('export.binder_date') }}</th><td>{{ $date }}</td></tr>
        @foreach ($fields as $field)
            <tr><th>{{ $field->label }}</th><td>{{ $field->type }}</td></tr>
        @endforeach
    </table>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('export.binder._style')</head>
<body>
    <h1 style="font-size: 28pt; margin-top: 60pt;">{{ $title }}</h1>
    <p class="muted">{{ $container->name }}</p>

    @if ($image !== null)
        <p><img src="{{ $image }}" style="max-width: 100%; max-height: 260pt;"></p>
    @endif

    <table>
        <tr><th>{{ __('export.owner') }}</th><td>{{ $owner }}</td></tr>
        <tr><th>{{ __('export.binder_date') }}</th><td>{{ $date }}</td></tr>
    </table>
</body>
</html>

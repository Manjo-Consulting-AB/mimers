<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('export.binder._style')</head>
<body>
    <h2 class="sub">{{ $filename }}</h2>
    @if ($image !== null)
        <img src="{{ $image }}" style="max-width: 100%; max-height: 640pt;">
    @endif
</body>
</html>

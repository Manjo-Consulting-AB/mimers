<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        {{-- Servern skriver sidhuvudet när sidan skickar `meta` som vydata —
             titel, beskrivning och kanonisk adress (ADR-0094 § 10). Utan dem
             ser huvudet ut som förut, så en sida utan `meta` får ingen
             beskrivning och ingen kanonisk adress. --}}
        @php
            $meta = $meta ?? [];
            $title = isset($meta['title']) && $meta['title'] !== ''
                ? $meta['title'].' · '.config('app.name')
                : config('app.name');
        @endphp
        <title inertia>{{ $title }}</title>
        @if (! empty($meta['description']))
            <meta name="description" content="{{ $meta['description'] }}">
        @endif
        @if (! empty($meta['canonical']))
            <link rel="canonical" href="{{ $meta['canonical'] }}">
        @endif

        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @inertiaHead
    </head>
    <body class="h-full font-sans antialiased">
        @inertia
    </body>
</html>

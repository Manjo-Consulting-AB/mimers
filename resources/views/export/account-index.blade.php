<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trans('export.account_heading') }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; padding: 2rem; background: #f4f5f7; color: #1f2937; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; line-height: 1.5; }
        main { max-width: 60rem; margin: 0 auto; }
        h1 { font-size: 1.75rem; margin: 0 0 .25rem; }
        .exported { color: #6b7280; font-size: .875rem; margin-bottom: 2rem; }
        h2 { font-size: 1.25rem; margin: 2rem 0 .5rem; padding-bottom: .25rem; border-bottom: 1px solid #d1d5db; }
        ul { margin: .25rem 0 .5rem; padding-left: 1.25rem; }
        a { color: #1d4ed8; text-decoration: none; }
        a:hover { text-decoration: underline; }
        .muted { color: #6b7280; }
    </style>
</head>
<body>
    <main>
        {{-- Startsidan i kontoexporten (issue 1701 · ADR-0098 § 1): en länk
             till varje containers egen index.html, utan externa resurser. --}}
        <h1>{{ trans('export.account_heading') }}</h1>
        <p class="exported">{{ trans('export.exported') }}: {{ $payload['exported_at'] }}</p>

        <h2>{{ trans('export.account_containers') }}</h2>
        @if (count($payload['containers']) > 0)
            <ul>
                @foreach ($payload['containers'] as $container)
                    <li><a href="{{ $container['path'] }}index.html">{{ $container['name'] }}</a></li>
                @endforeach
            </ul>
        @else
            <p class="muted">{{ trans('export.account_no_containers') }}</p>
        @endif

        <h2>{{ trans('export.account_person') }}</h2>
        <p><a href="account.json">{{ trans('export.account_person_file') }}</a></p>

        @if (count($payload['shared_with_me']) > 0)
            <h2>{{ trans('export.account_shared') }}</h2>
            <p class="muted">{{ trans('export.account_shared_note') }}</p>
            <ul>
                @foreach ($payload['shared_with_me'] as $shared)
                    <li>{{ $shared['container'] }} — {{ trans('export.account_shared_owner') }}: {{ $shared['owner'] }}, {{ trans('export.account_shared_level') }}: {{ $shared['level'] }}</li>
                @endforeach
            </ul>
        @endif

        <p class="muted">{{ trans('export.account_not_included') }}</p>
    </main>
</body>
</html>

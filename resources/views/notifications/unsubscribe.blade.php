<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ trans('notiser.unsubscribe.confirm_heading', ['type' => $typeLabel]) }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f5f7; color: #1f2937; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; }
        main { background: #fff; padding: 2rem; border-radius: 12px; box-shadow: 0 1px 3px rgb(0 0 0 / .12); max-width: 26rem; margin: 1rem; }
        h1 { font-size: 1.25rem; margin: 0 0 1.5rem; }
        button { width: 100%; padding: .7rem 1rem; border: 0; border-radius: 8px; background: #111827; color: #fff; font-size: 1rem; cursor: pointer; }
        button:hover { background: #374151; }
    </style>
</head>
<body>
    <main>
        <h1>{{ trans('notiser.unsubscribe.confirm_heading', ['type' => $typeLabel]) }}</h1>
        <form method="POST" action="{{ $url }}">
            <button type="submit">{{ trans('notiser.unsubscribe.confirm_button') }}</button>
        </form>
    </main>
</body>
</html>

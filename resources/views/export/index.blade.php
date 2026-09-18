<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $containerName }}</title>
    <style>
        :root { color-scheme: light dark; }
        body { margin: 0; padding: 2rem; background: #f4f5f7; color: #1f2937; font-family: system-ui, -apple-system, 'Segoe UI', sans-serif; line-height: 1.5; }
        main { max-width: 60rem; margin: 0 auto; }
        h1 { font-size: 1.75rem; margin: 0 0 .25rem; }
        .exported { color: #6b7280; font-size: .875rem; margin-bottom: 2rem; }
        h2 { font-size: 1.25rem; margin: 2rem 0 .5rem; padding-bottom: .25rem; border-bottom: 1px solid #d1d5db; }
        dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem 1rem; margin: .5rem 0; }
        dt { font-weight: 600; }
        dd { margin: 0; }
        ul { margin: .25rem 0 .5rem; padding-left: 1.25rem; }
        .toc a { color: #1d4ed8; text-decoration: none; }
        .toc a:hover { text-decoration: underline; }
        .chips { list-style: none; padding: 0; display: flex; flex-wrap: wrap; gap: .375rem; }
        .chip { background: #e5e7eb; border-radius: 9999px; padding: .125rem .625rem; font-size: .8125rem; }
        .sub { margin: .75rem 0 .25rem; font-weight: 600; }
        .muted { color: #6b7280; }
        .missing { color: #b45309; }
    </style>
</head>
<body>
    <main>
        @php
            $itemsByUlid = collect($payload['items'])->keyBy('ulid');
            $tagsByUlid = collect($payload['tags'])->keyBy('ulid');
            $categoriesByUlid = collect($payload['categories'])->keyBy('ulid');

            // Etiketterna läses ur lang/en/export.php — den enda katalogen,
            // se [[ADR-0034 Engelska vid lansering]] — samma form som mejlen
            // mot notiser.php. `$label` bygger nyckeln för de värden
            // som kommer ur databasen (recurrence_type, status, relation) och
            // faller tillbaka på råvärdet om nyckeln saknas — en intern kod
            // ska aldrig visas som en översättningsnyckel.
            $translator = app('translator');
            $label = fn (string $prefix, string $value): string => $translator->has('export.'.$prefix.'_'.$value)
                ? trans('export.'.$prefix.'_'.$value)
                : $value;
        @endphp

        <h1>{{ trans('export.heading') }} {{ $containerName }}</h1>
        <p class="exported">{{ trans('export.exported') }}: {{ $payload['exported_at'] }}</p>

        @if (count($payload['items']) > 0)
            <h2>{{ trans('export.contents') }}</h2>
            <ul class="toc">
                @foreach ($payload['items'] as $item)
                    <li><a href="#{{ $item['ulid'] }}">{{ $item['name'] }}</a></li>
                @endforeach
            </ul>
        @endif

        @foreach ($payload['items'] as $item)
            <h2 id="{{ $item['ulid'] }}">{{ $item['name'] }}</h2>

            <dl>
                @if ($item['category_ulid'] !== null)
                    <dt>{{ trans('export.category') }}</dt>
                    <dd>{{ $categoriesByUlid[$item['category_ulid']]['name'] ?? trans('export.no_value') }}</dd>
                @endif
                @if ($item['description'] !== null)
                    <dt>{{ trans('export.description') }}</dt>
                    <dd>{{ $item['description'] }}</dd>
                @endif
                @if ($item['manufacturer'] !== null)
                    <dt>{{ trans('export.manufacturer') }}</dt>
                    <dd>{{ $item['manufacturer'] }}</dd>
                @endif
                @if ($item['model'] !== null)
                    <dt>{{ trans('export.model') }}</dt>
                    <dd>{{ $item['model'] }}</dd>
                @endif
                @if ($item['serial_number'] !== null)
                    <dt>{{ trans('export.serial_number') }}</dt>
                    <dd>{{ $item['serial_number'] }}</dd>
                @endif
                @if ($item['purchased_at'] !== null)
                    <dt>{{ trans('export.purchased_at') }}</dt>
                    <dd>{{ $item['purchased_at'] }}</dd>
                @endif
                @if ($item['warranty_until'] !== null)
                    <dt>{{ trans('export.warranty_until') }}</dt>
                    <dd>{{ $item['warranty_until'] }}</dd>
                @endif
                @if ($item['position_note'] !== null)
                    <dt>{{ trans('export.position_note') }}</dt>
                    <dd>{{ $item['position_note'] }}</dd>
                @endif
            </dl>

            @if (count($item['tags']) > 0)
                <p class="sub">{{ trans('export.tags') }}</p>
                <ul class="chips">
                    @foreach ($item['tags'] as $tagUlid)
                        <li class="chip">{{ $tagsByUlid[$tagUlid]['name'] ?? $tagUlid }}</li>
                    @endforeach
                </ul>
            @endif

            @if (count($item['links']) > 0)
                <p class="sub">{{ trans('export.links') }}</p>
                <ul>
                    @foreach ($item['links'] as $link)
                        <li>{{ $label('relation', $link['relation']) }}
                            {{ $itemsByUlid[$link['item_ulid']]['name'] ?? $link['item_ulid'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if (count($item['schedules']) > 0)
                <p class="sub">{{ trans('export.schedules') }}</p>
                @foreach ($item['schedules'] as $schedule)
                    <p><strong>{{ $schedule['title'] }}</strong>
                        @if ($schedule['recurrence_type'] !== null)
                            · {{ trans('export.recurrence') }}: {{ $label('recurrence', $schedule['recurrence_type']) }}
                        @endif
                    </p>
                    @if ($schedule['notes'] !== null)
                        <p class="muted">{{ $schedule['notes'] }}</p>
                    @endif
                    @if (count($schedule['occurrences']) > 0)
                        <ul>
                            @foreach ($schedule['occurrences'] as $occurrence)
                                <li>
                                    @if ($occurrence['status'] !== 'open')
                                        {{ $label('status', $occurrence['status']) }} ·
                                    @endif
                                    {{ trans('export.due_at') }}: {{ $occurrence['due_at'] }}
                                    @if ($occurrence['completed_at'] !== null)
                                        · {{ trans('export.completed_at') }}: {{ $occurrence['completed_at'] }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            @endif

            @if (count($item['loans']) > 0)
                <p class="sub">{{ trans('export.loans') }}</p>
                @foreach ($item['loans'] as $loan)
                    <dl>
                        <dt>{{ trans('export.borrower') }}</dt>
                        <dd>{{ $loan['borrower_name'] }}
                            @if ($loan['borrower_email'] !== null)
                                ({{ $loan['borrower_email'] }})
                            @endif
                        </dd>
                        <dt>{{ trans('export.lent_at') }}</dt>
                        <dd>{{ $loan['lent_at'] }}</dd>
                        @if ($loan['due_at'] !== null)
                            <dt>{{ trans('export.due_at') }}</dt>
                            <dd>{{ $loan['due_at'] }}</dd>
                        @endif
                        @if ($loan['returned_at'] !== null)
                            <dt>{{ trans('export.returned_at') }}</dt>
                            <dd>{{ $loan['returned_at'] }}</dd>
                        @endif
                        @if ($loan['note'] !== null)
                            <dt>{{ trans('export.note') }}</dt>
                            <dd>{{ $loan['note'] }}</dd>
                        @endif
                    </dl>
                @endforeach
            @endif

            @if (count($item['attachments']) > 0)
                <p class="sub">{{ trans('export.attachments') }}</p>
                <ul>
                    @foreach ($item['attachments'] as $attachment)
                        <li>
                            @if (isset($attachment['path']))
                                <a href="{{ $attachment['path'] }}">{{ $attachment['filename'] }}</a>
                            @else
                                {{ $attachment['filename'] }} <span class="missing">({{ trans('export.missing') }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    </main>
</body>
</html>

<!DOCTYPE html>
<html lang="{{ $locale === 'sv' ? 'sv' : 'en' }}">
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
            $texts = $locale === 'sv' ? [
                'heading' => 'Export av',
                'exported' => 'Exporterad',
                'contents' => 'Innehåll',
                'category' => 'Kategori',
                'description' => 'Beskrivning',
                'manufacturer' => 'Tillverkare',
                'model' => 'Modell',
                'serial_number' => 'Serienummer',
                'purchased_at' => 'Inköpt',
                'warranty_until' => 'Garanti till',
                'position_note' => 'Placering',
                'tags' => 'Taggar',
                'links' => 'Länkar',
                'schedules' => 'Scheman',
                'loans' => 'Utlåningar',
                'attachments' => 'Bilagor',
                'recurrence' => 'Upprepning',
                'occurrences' => 'Förekomster',
                'visible_from' => 'Synlig från',
                'due_at' => 'Förfaller',
                'completed_at' => 'Klar',
                'status' => 'Status',
                'borrower' => 'Låntagare',
                'lent_at' => 'Utlånad',
                'returned_at' => 'Återlämnad',
                'note' => 'Anteckning',
                'no_value' => '—',
                'missing' => 'Filens byten saknas på disken',
                'none' => 'Inga',
                'recurrence_none' => 'Engång',
                'recurrence_fixed' => 'Fast datum',
                'recurrence_interval' => 'Intervall',
                'status_open' => 'Öppen',
                'status_completed' => 'Klar',
                'status_skipped' => 'Hoppad över',
                'relation_parent' => 'Förälder:',
                'relation_child' => 'Barn:',
                'relation_sibling' => 'Syskon:',
            ] : [
                'heading' => 'Export of',
                'exported' => 'Exported',
                'contents' => 'Contents',
                'category' => 'Category',
                'description' => 'Description',
                'manufacturer' => 'Manufacturer',
                'model' => 'Model',
                'serial_number' => 'Serial number',
                'purchased_at' => 'Purchased',
                'warranty_until' => 'Warranty until',
                'position_note' => 'Position',
                'tags' => 'Tags',
                'links' => 'Links',
                'schedules' => 'Schedules',
                'loans' => 'Loans',
                'attachments' => 'Attachments',
                'recurrence' => 'Recurrence',
                'occurrences' => 'Occurrences',
                'visible_from' => 'Visible from',
                'due_at' => 'Due',
                'completed_at' => 'Completed',
                'status' => 'Status',
                'borrower' => 'Borrower',
                'lent_at' => 'Lent',
                'returned_at' => 'Returned',
                'note' => 'Note',
                'no_value' => '—',
                'missing' => 'File bytes missing on disk',
                'none' => 'None',
                'recurrence_none' => 'Once',
                'recurrence_fixed' => 'Fixed date',
                'recurrence_interval' => 'Interval',
                'status_open' => 'Open',
                'status_completed' => 'Completed',
                'status_skipped' => 'Skipped',
                'relation_parent' => 'Parent:',
                'relation_child' => 'Child:',
                'relation_sibling' => 'Sibling:',
            ];

            $itemsByUlid = collect($payload['items'])->keyBy('ulid');
            $tagsByUlid = collect($payload['tags'])->keyBy('ulid');
            $categoriesByUlid = collect($payload['categories'])->keyBy('ulid');

            $recurrenceLabel = fn (string $type): string => $texts['recurrence_'.$type] ?? $type;
            $statusLabel = fn (string $status): string => $texts['status_'.$status] ?? $status;
        @endphp

        <h1>{{ $texts['heading'] }} {{ $containerName }}</h1>
        <p class="exported">{{ $texts['exported'] }}: {{ $payload['exported_at'] }}</p>

        @if (count($payload['items']) > 0)
            <h2>{{ $texts['contents'] }}</h2>
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
                    <dt>{{ $texts['category'] }}</dt>
                    <dd>{{ $categoriesByUlid[$item['category_ulid']]['name'] ?? $texts['no_value'] }}</dd>
                @endif
                @if ($item['description'] !== null)
                    <dt>{{ $texts['description'] }}</dt>
                    <dd>{{ $item['description'] }}</dd>
                @endif
                @if ($item['manufacturer'] !== null)
                    <dt>{{ $texts['manufacturer'] }}</dt>
                    <dd>{{ $item['manufacturer'] }}</dd>
                @endif
                @if ($item['model'] !== null)
                    <dt>{{ $texts['model'] }}</dt>
                    <dd>{{ $item['model'] }}</dd>
                @endif
                @if ($item['serial_number'] !== null)
                    <dt>{{ $texts['serial_number'] }}</dt>
                    <dd>{{ $item['serial_number'] }}</dd>
                @endif
                @if ($item['purchased_at'] !== null)
                    <dt>{{ $texts['purchased_at'] }}</dt>
                    <dd>{{ $item['purchased_at'] }}</dd>
                @endif
                @if ($item['warranty_until'] !== null)
                    <dt>{{ $texts['warranty_until'] }}</dt>
                    <dd>{{ $item['warranty_until'] }}</dd>
                @endif
                @if ($item['position_note'] !== null)
                    <dt>{{ $texts['position_note'] }}</dt>
                    <dd>{{ $item['position_note'] }}</dd>
                @endif
            </dl>

            @if (count($item['tags']) > 0)
                <p class="sub">{{ $texts['tags'] }}</p>
                <ul class="chips">
                    @foreach ($item['tags'] as $tagUlid)
                        <li class="chip">{{ $tagsByUlid[$tagUlid]['name'] ?? $tagUlid }}</li>
                    @endforeach
                </ul>
            @endif

            @if (count($item['links']) > 0)
                <p class="sub">{{ $texts['links'] }}</p>
                <ul>
                    @foreach ($item['links'] as $link)
                        <li>{{ $texts['relation_'.$link['relation']] ?? $link['relation'] }}
                            {{ $itemsByUlid[$link['item_ulid']]['name'] ?? $link['item_ulid'] }}</li>
                    @endforeach
                </ul>
            @endif

            @if (count($item['schedules']) > 0)
                <p class="sub">{{ $texts['schedules'] }}</p>
                @foreach ($item['schedules'] as $schedule)
                    <p><strong>{{ $schedule['title'] }}</strong>
                        @if ($schedule['recurrence_type'] !== null)
                            · {{ $texts['recurrence'] }}: {{ $recurrenceLabel($schedule['recurrence_type']) }}
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
                                        {{ $statusLabel($occurrence['status']) }} ·
                                    @endif
                                    {{ $texts['due_at'] }}: {{ $occurrence['due_at'] }}
                                    @if ($occurrence['completed_at'] !== null)
                                        · {{ $texts['completed_at'] }}: {{ $occurrence['completed_at'] }}
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            @endif

            @if (count($item['loans']) > 0)
                <p class="sub">{{ $texts['loans'] }}</p>
                @foreach ($item['loans'] as $loan)
                    <dl>
                        <dt>{{ $texts['borrower'] }}</dt>
                        <dd>{{ $loan['borrower_name'] }}
                            @if ($loan['borrower_email'] !== null)
                                ({{ $loan['borrower_email'] }})
                            @endif
                        </dd>
                        <dt>{{ $texts['lent_at'] }}</dt>
                        <dd>{{ $loan['lent_at'] }}</dd>
                        @if ($loan['due_at'] !== null)
                            <dt>{{ $texts['due_at'] }}</dt>
                            <dd>{{ $loan['due_at'] }}</dd>
                        @endif
                        @if ($loan['returned_at'] !== null)
                            <dt>{{ $texts['returned_at'] }}</dt>
                            <dd>{{ $loan['returned_at'] }}</dd>
                        @endif
                        @if ($loan['note'] !== null)
                            <dt>{{ $texts['note'] }}</dt>
                            <dd>{{ $loan['note'] }}</dd>
                        @endif
                    </dl>
                @endforeach
            @endif

            @if (count($item['attachments']) > 0)
                <p class="sub">{{ $texts['attachments'] }}</p>
                <ul>
                    @foreach ($item['attachments'] as $attachment)
                        <li>
                            @if (isset($attachment['path']))
                                <a href="{{ $attachment['path'] }}">{{ $attachment['filename'] }}</a>
                            @else
                                {{ $attachment['filename'] }} <span class="missing">({{ $texts['missing'] }})</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        @endforeach
    </main>
</body>
</html>

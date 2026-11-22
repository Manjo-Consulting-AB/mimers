<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('export.binder._style')</head>
<body>
    <h{{ $level }}>{{ $item->name }}</h{{ $level }}>

    <table>
        @if ($item->category !== null)
            <tr><th>{{ __('export.category') }}</th><td>{{ $item->category->name }}</td></tr>
        @endif
        @if ($item->tags->isNotEmpty())
            <tr><th>{{ __('export.tags') }}</th><td>{{ $item->tags->pluck('name')->implode(', ') }}</td></tr>
        @endif
        @foreach ($fields as $field)
            @if ($field['value'] !== null)
                <tr><th>{{ $field['label'] }}</th><td>{{ $field['value'] }}</td></tr>
            @endif
        @endforeach
    </table>

    @if (filled($item->description))
        <p>{{ $item->description }}</p>
    @endif

    @if ($notesHtml !== null)
        <h2 class="sub">{{ __('export.notes') }}</h2>
        {!! $notesHtml !!}
    @endif

    @if (in_array('relations', $sections, true) && count($relations) > 0)
        <h2 class="sub">{{ __('export.links') }}</h2>
        <table>
            @foreach ($relations as $relation)
                <tr><td>{{ __('export.relation_'.$relation['relation']) }} {{ $relation['name'] }}</td></tr>
            @endforeach
        </table>
    @endif

    @if (in_array('schedules', $sections, true) && $item->schedules->isNotEmpty())
        <h2 class="sub">{{ __('export.schedules') }}</h2>
        @foreach ($item->schedules as $schedule)
            <p><strong>{{ $schedule->title }}</strong></p>
            @if ($schedule->occurrences->isNotEmpty())
                <table>
                    @foreach ($schedule->occurrences as $occurrence)
                        <tr>
                            <td style="width: 70pt;">{{ $occurrence->completed_at?->toDateString() }}</td>
                            <td>{{ $occurrence->completion_note }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach
    @endif

    @if ($item->loans->isNotEmpty())
        <h2 class="sub">{{ __('export.loans') }}</h2>
        <table>
            @foreach ($item->loans as $loan)
                <tr>
                    <td>{{ $loan->borrower_name }}</td>
                    <td>{{ $loan->lent_at?->toDateString() }}</td>
                    <td>{{ $loan->returned_at?->toDateString() ?? __('export.no_value') }}</td>
                    <td>{{ $loan->note }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if (in_array('history', $sections, true) && count($history) > 0)
        <h2 class="sub">{{ __('export.history') }}</h2>
        <table>
            @foreach ($history as $row)
                <tr>
                    <td style="width: 80pt;">{{ $row['date'] }}</td>
                    <td>{{ $row['sentence'] }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    @if ($costs !== null)
        <h2 class="sub">{{ __('export.costs') }}</h2>
        <table>
            @foreach ($costs['rows'] as $row)
                <tr>
                    <td style="width: 70pt;">{{ $row['date'] }}</td>
                    <td>{{ $row['description'] }}@if ($row['supplier']) <span class="muted">({{ $row['supplier'] }})</span>@endif</td>
                    <td style="text-align: right;">{{ $row['amount'] }}</td>
                </tr>
            @endforeach
            @foreach ($costs['totals'] as $total)
                <tr><th colspan="2">{{ __('export.costs_total') }} {{ $total['currency'] }}</th><th style="text-align: right;">{{ $total['amount'] }}</th></tr>
            @endforeach
        </table>
    @endif
</body>
</html>

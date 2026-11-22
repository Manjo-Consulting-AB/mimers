<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8">@include('export.binder._style')</head>
<body>
    <h1>{{ __('export.contents') }}</h1>
    <table>
        @foreach ($rows as $row)
            <tr>
                <td style="padding-left: {{ 4 + $row['depth'] * 14 }}pt;">{{ $row['name'] }}</td>
                <td style="width: 40pt; text-align: right;">{{ $row['page'] }}</td>
            </tr>
        @endforeach
    </table>
</body>
</html>

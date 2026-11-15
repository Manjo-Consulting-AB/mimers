<x-mail::message>
# {{ trans('prestanda.heading', [], 'en') }}

{{ trans('prestanda.requests', ['this' => $requestsThisWeek, 'previous' => $requestsPreviousWeek], 'en') }}

## {{ trans('prestanda.routes_heading', [], 'en') }}

@forelse ($routes as $row)
- {{ trans('prestanda.route_line', ['route' => $row['route'], 'median' => round($row['median']), 'p95' => round($row['p95']), 'count' => $row['count']], 'en') }}
@empty
{{ trans('prestanda.none', [], 'en') }}
@endforelse

## {{ trans('prestanda.queries_heading', [], 'en') }}

@forelse ($queries as $row)
- {{ trans('prestanda.query_line', ['count' => $row['count'], 'max' => round($row['max']), 'sql' => str_replace('`', '', $row['sql'])], 'en') }}
@empty
{{ trans('prestanda.none', [], 'en') }}
@endforelse
</x-mail::message>

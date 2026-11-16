<?php

// Issue 390 · veckosammanställningen av prestandaloggen, se
// App\Mail\PerformanceSummaryMail.
return [
    'subject' => 'Weekly performance summary',
    'heading' => 'Weekly performance summary',
    'requests' => 'Requests over the threshold: :this this week, :previous the week before.',
    'routes_heading' => 'Slowest routes',
    'route_line' => ':route — median :median ms, 95th percentile :p95 ms, :count slow requests',
    'queries_heading' => 'Most common slow queries',
    'query_line' => ':count × (slowest :max ms) `:sql`',
    'none' => 'Nothing this week.',
];

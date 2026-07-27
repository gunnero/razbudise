<?php

return [
    /*
    | Raw, pseudonymous interaction events are retained only as long as they
    | are useful for deduplication and period-level unique counts. Daily
    | aggregates contain no visitor or session identifiers and are retained.
    */
    'raw_event_retention_days' => (int) env('EDITORIAL_ANALYTICS_RAW_RETENTION_DAYS', 395),
    'report_cache_seconds' => (int) env('EDITORIAL_ANALYTICS_CACHE_SECONDS', 300),
    'rollup_lookback_days' => (int) env('EDITORIAL_ANALYTICS_ROLLUP_LOOKBACK_DAYS', 3),
];

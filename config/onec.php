<?php

return [
    // Manual commands remain available; only automatic scheduling is gated.
    'scheduled' => (bool) env('ONEC_SCHEDULE_ENABLED', false),
    // The current 1C export is a complete stock snapshot; false is an explicit override.
    'full_snapshot' => (bool) env('ONEC_FULL_SNAPSHOT', true),
    // Enable only after verifying that this FTP producer preserves generation order.
    'order_source' => env('ONEC_ORDER_SOURCE'),
    'directory' => env('ONEC_INPUT_DIRECTORY', '/var/www/vhosts/autohimiki.kz/httpdocs/import1'),
    'staging' => storage_path('app/private/onec'),
    'stable_seconds' => (int) env('ONEC_STABLE_SECONDS', 60),
    'max_bytes' => 50 * 1024 * 1024,
    'max_uncompressed_bytes' => 150 * 1024 * 1024,
    'max_rows' => 20000,
];

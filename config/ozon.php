<?php

return [
    'client_id' => env('OZON_CLIENT_ID'),
    'api_key' => env('OZON_API_KEY'),
    // Enable only after separately authorized verification of the seller import flow.
    'enabled' => env('OZON_ENABLED', false),
    'currency' => env('OZON_CURRENCY', 'KZT'),
    'vat' => env('OZON_VAT'),
    'warehouse_id' => env('OZON_WAREHOUSE_ID'),
    'timeout' => 30,
    'attempts' => 3,
    'backoff_ms' => 500,
];

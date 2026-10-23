<?php

return [
    // Store only SHA-256 hashes. An empty configuration disables the API.
    'write_token_hash' => env('SEO_MANAGER_WRITE_TOKEN_SHA256', ''),
    'read_token_hash' => env('SEO_MANAGER_READ_TOKEN_SHA256', ''),
    'allow_local_http' => (bool) env('SEO_MANAGER_ALLOW_LOCAL_HTTP', false),
];

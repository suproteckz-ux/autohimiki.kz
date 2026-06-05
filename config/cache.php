<?php

use Illuminate\Support\Str;

return [
    'default' => env('CACHE_STORE', 'file'),

    'stores' => [
        'array' => [
            'driver'    => 'array',
            'serialize' => false,
        ],
        'file' => [
            'driver'    => 'file',
            'path'      => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],
        'redis' => [
            'driver'          => 'redis',
            'connection'      => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],
        'null' => [
            'driver' => 'null',
        ],
    ],

    // ИСПРАВЛЕНО: добавлен use Illuminate\Support\Str
    'prefix' => env(
        'CACHE_PREFIX',
        Str::slug(env('APP_NAME', 'autohimiki'), '_') . '_cache_'
    ),
];

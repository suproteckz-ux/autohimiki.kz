<?php

/**
 * config/image.php — Intervention Image v3
 *
 * Настройка драйвера обработки изображений.
 *
 * Доступные драйверы:
 *   \Intervention\Image\Drivers\Gd\Driver::class      — требует ext-gd
 *   \Intervention\Image\Drivers\Imagick\Driver::class — требует ext-imagick
 *
 * На hoster.kz (shared hosting):
 *   - ext-gd недоступна в PHP CLI 8.3
 *   - ext-imagick обычно доступна
 *
 * Если недоступен ни один из драйверов — конвертация WebP отключается
 * и сохраняется только оригинальное изображение (без потери функционала).
 */
return [
    'driver' => env(
        'IMAGE_DRIVER',
        extension_loaded('imagick')
            ? \Intervention\Image\Drivers\Imagick\Driver::class
            : \Intervention\Image\Drivers\Gd\Driver::class
    ),
];

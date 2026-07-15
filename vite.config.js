import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
            ],
            refresh: true,
        }),
    ],

    build: {
        // Минификация — terser более агрессивен чем esbuild
        minify: 'terser',
        terserOptions: {
            compress: {
                drop_console: true,   // убираем console.log в продакшене
                drop_debugger: true,
                passes: 2,            // два прохода для лучшего сжатия
            },
            mangle: {
                safari10: true,       // совместимость с Safari 10
            },
        },

        // CSS inlining: < 4KB инлайним в HTML (ускоряет FCP)
        cssCodeSplit: true,           // CSS остается отдельным entry для Vite 5

        // Rollup настройки
        rollupOptions: {
            output: {
                // Разделяем vendor (Alpine.js) в отдельный чанк
                // Браузер кэширует vendor отдельно — при обновлении app.js vendor остаётся
                manualChunks: {
                    alpine: ['alpinejs'],
                },
                // Имена с хешем для cache busting
                entryFileNames:   'assets/[name]-[hash].js',
                chunkFileNames:   'assets/[name]-[hash].js',
                assetFileNames:   'assets/[name]-[hash][extname]',
            },
        },

        // Предупреждать если чанк > 500KB
        chunkSizeWarningLimit: 500,

        // Sourcemaps только для staging, не для production
        sourcemap: false,
    },

    // CSS оптимизация
    css: {
        devSourcemap: true,
        preprocessorOptions: {},
    },
})

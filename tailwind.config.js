/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './resources/views/**/*.blade.php',
        './resources/js/**/*.js',
        './app/Filament/**/*.php',
    ],
    theme: {
        extend: {
            colors: {
                primary: {
                    50:  '#fffbeb', 100: '#fef3c7', 200: '#fde68a',
                    300: '#fcd34d', 400: '#fbbf24', 500: '#f59e0b',
                    600: '#d97706', 700: '#b45309', 800: '#92400e', 900: '#78350f',
                },
            },
            fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
        },
    },
    plugins: [
        require('@tailwindcss/typography'),
        require('@tailwindcss/forms'),
        require('@tailwindcss/aspect-ratio'),
    ],
}

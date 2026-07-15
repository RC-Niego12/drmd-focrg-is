/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './resources/**/*.blade.php',
        './resources/**/*.js',
        './resources/**/*.jsx',
    ],
    theme: {
        extend: {
            colors: {
                brand: {
                    50: '#eef8f4',
                    100: '#d7efe6',
                    200: '#b3ddd0',
                    300: '#7fc0aa',
                    400: '#4ea489',
                    500: '#2f8f73',
                    600: '#26725d',
                    700: '#205d4e',
                    800: '#1b4b40',
                    900: '#173e35',
                    950: '#0c221d',
                },
                signal: {
                    amber: '#c9821d',
                    coral: '#c75050',
                    blue: '#246b9f',
                },
            },
        },
    },
    plugins: [],
};

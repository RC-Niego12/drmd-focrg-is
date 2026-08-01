import react from 'eslint-plugin-react';

export default [
    {
        ignores: ['node_modules/**', 'public/build/**', 'vendor/**'],
    },
    {
        files: ['resources/js/**/*.{js,jsx}'],
        languageOptions: {
            ecmaVersion: 'latest',
            sourceType: 'module',
            parserOptions: {
                ecmaFeatures: { jsx: true },
            },
        },
        plugins: { react },
        settings: {
            react: { version: 'detect' },
        },
        rules: {
            'react/jsx-no-undef': 'error',
            'react/jsx-uses-vars': 'error',
            'react/jsx-key': 'error',
        },
    },
];

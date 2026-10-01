<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => request()->cookie('theme') === 'dark'])>
    @php($initialSystemName = data_get($page ?? [], 'props.systemNameLong', config('app.name')))
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="theme-color" content="#0f766e">
        <meta name="application-name" content="DROMIS">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="DROMIS">
        <meta name="format-detection" content="telephone=no">
        <meta name="color-scheme" content="light dark">
        <title inertia>{{ $initialSystemName }}</title>
        <script>window.__SYSTEM_NAME__ = @json($initialSystemName);</script>
        <link rel="icon" href="/images/drmd-cir-logo.png" type="image/png">
        <link rel="apple-touch-icon" href="/images/pwa-icon-192.png">
        <link rel="manifest" href="/manifest.json" crossorigin="use-credentials">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

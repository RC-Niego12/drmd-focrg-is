<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => request()->cookie('theme') === 'dark'])>
    @php($initialSystemName = data_get($page ?? [], 'props.systemNameLong', config('app.name')))
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title inertia>{{ $initialSystemName }}</title>
        <script>window.__SYSTEM_NAME__ = @json($initialSystemName);</script>
        <link rel="icon" href="/images/drmd-cir-logo.png" type="image/png">
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>

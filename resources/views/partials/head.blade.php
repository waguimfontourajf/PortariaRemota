<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Portaria Remota') : config('app.name', 'Portaria Remota') }}
</title>

@auth
    <meta name="tempo-real" content="{{ route('novidades') }}">
@endauth

<link rel="icon" href="/favicon.ico" sizes="any">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#18181b">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="Portaria">
<meta name="apple-mobile-web-app-status-bar-style" content="black">


@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance

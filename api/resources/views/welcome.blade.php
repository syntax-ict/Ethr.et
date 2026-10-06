<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'ETHR') }} API</title>
    {{-- Laravel's stock welcome page used to live here, with a @vite tag for a
         front-end skeleton nothing built (removed 2026-10-01, audit I5). The
         product's front end is the Next.js static export; this host answers
         /api/v1. In production the export serves "/", so this page is what a
         bare `php artisan serve` shows. --}}
    <style>
        body { font: 16px/1.5 system-ui, sans-serif; margin: 0; padding: 3rem 1rem; color: #1f2937; background: #f9fafb; }
        main { max-width: 32rem; margin: 0 auto; }
        a { color: #2563eb; }
        @media (prefers-color-scheme: dark) { body { color: #e5e7eb; background: #111827; } a { color: #93c5fd; } }
    </style>
</head>
<body>
<main>
    <h1>{{ config('app.name', 'ETHR') }} API</h1>
    <p>This host serves the API under <code>/api/v1</code>.</p>
    <p><a href="{{ url('/api/docs') }}">API documentation</a></p>
</main>
</body>
</html>

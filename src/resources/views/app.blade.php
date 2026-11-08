<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="same-origin">
    <title inertia>WeavDash</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}?v=20261108" sizes="16x16 32x32 48x48" type="image/x-icon">
    <link rel="icon" href="{{ asset('favicon.svg') }}?v=20261108" sizes="any" type="image/svg+xml">
    @vite('resources/js/app.ts')
    @inertiaHead
  </head>
  <body>
    @inertia
  </body>
</html>

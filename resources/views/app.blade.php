<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
        <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#0a0a0a" media="(prefers-color-scheme: dark)">
        <link rel="manifest" href="/manifest.webmanifest">
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="{{ config('app.name', 'Freyr') }}">

        {{-- iOS standalone launch screens (portrait) — without these the app
             flashes white on cold launch instead of showing a native splash. --}}
        @php
            $splashScreens = [
                // [device-width, device-height, ratio, image-width, image-height]
                [375, 667, 2, 750, 1334],   // iPhone SE (2nd/3rd gen), 8
                [414, 896, 2, 828, 1792],   // iPhone XR, 11
                [375, 812, 3, 1125, 2436],  // iPhone X/XS, 11 Pro, 12/13 mini
                [390, 844, 3, 1170, 2532],  // iPhone 12–14
                [393, 852, 3, 1179, 2556],  // iPhone 14 Pro, 15, 15 Pro, 16
                [402, 874, 3, 1206, 2622],  // iPhone 16 Pro
                [428, 926, 3, 1284, 2778],  // iPhone 12/13 Pro Max, 14 Plus
                [430, 932, 3, 1290, 2796],  // iPhone 14/15 Pro Max, 15/16 Plus
                [440, 956, 3, 1320, 2868],  // iPhone 16 Pro Max
            ];
        @endphp
        @php
            // An explicit app theme (cookie) wins over the OS scheme, so emit
            // only that scheme's launch image — otherwise iOS would show the
            // OS-colored splash and the app would flash to the other theme.
            $splashSchemes = in_array($appearance ?? null, ['light', 'dark'], true)
                ? [$appearance]
                : ['light', 'dark'];
        @endphp
        @foreach ($splashScreens as [$width, $height, $ratio, $imageWidth, $imageHeight])
            @foreach ($splashSchemes as $scheme)
                <link rel="apple-touch-startup-image" href="/splash/screen-{{ $imageWidth }}x{{ $imageHeight }}{{ $scheme === 'dark' ? '-dark' : '' }}.png" media="(device-width: {{ $width }}px) and (device-height: {{ $height }}px) and (-webkit-device-pixel-ratio: {{ $ratio }}) and (orientation: portrait){{ $appearance === 'system' || $appearance === null ? " and (prefers-color-scheme: {$scheme})" : '' }}">
            @endforeach
        @endforeach

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        {{-- Matches the sidebar frame color, which fills the viewport in the inset layout --}}
        <style>
            html {
                background-color: oklch(0.972 0.002 286);
            }

            html.dark {
                background-color: oklch(0.105 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Freyr') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>

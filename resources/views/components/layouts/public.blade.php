<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
        <meta name="robots" content="noindex, nofollow">
    </head>
    <body class="min-h-screen bg-white antialiased dark:bg-linear-to-b dark:from-neutral-950 dark:to-neutral-900">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-10">
            <div class="flex items-center gap-2">
                <x-app-logo />
            </div>
            {{ $slot }}
        </div>
        @fluxScripts
    </body>
</html>

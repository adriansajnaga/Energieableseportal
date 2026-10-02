<x-layouts.app.sidebar :title="$title ?? null">
    <flux:main class="flex min-h-screen flex-col">
        <div class="flex-1">
            {{ $slot }}
        </div>
        <x-app-footer class="mt-10" />
    </flux:main>
</x-layouts.app.sidebar>

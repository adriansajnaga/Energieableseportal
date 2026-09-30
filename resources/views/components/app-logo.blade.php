<div class="flex aspect-square size-8 items-center justify-center rounded-md bg-white p-0.5 ring-1 ring-zinc-200 dark:ring-0">
    <x-app-logo-icon class="size-full" />
</div>
<div class="ml-1 grid flex-1 text-left text-sm">
    <span class="mb-0.5 truncate leading-none font-semibold">{{ config('app.name') }}</span>
    <span class="truncate text-xs text-zinc-500">{{ \App\Models\Setting::get('landlord_name') }}</span>
</div>

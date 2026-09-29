@props(['title', 'subtitle' => null])

<div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div>
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:subheading>{{ $subtitle }}</flux:subheading>
        @endif
    </div>
    <div class="flex flex-wrap items-center gap-2">
        {{ $slot }}
    </div>
</div>

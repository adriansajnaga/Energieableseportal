@props(['period'])

<div class="flex items-center gap-1">
    <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="previousMonth" :aria-label="__('Vormonat')" />
    <span class="min-w-32 text-center text-sm font-semibold">{{ $period->locale(app()->getLocale())->isoFormat('MMMM YYYY') }}</span>
    <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="nextMonth" :aria-label="__('Folgemonat')" />
</div>

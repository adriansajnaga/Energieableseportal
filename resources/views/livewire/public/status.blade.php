<?php

use App\Models\Meter;
use App\Models\Setting;
use App\Services\Statistics;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

/*
 * Ablesestatus ohne Anmeldung (wie früher status.php), geschützt durch einen geheimen Link
 * aus den Einstellungen. Zeigt nur Zähler ohne Ablesung im laufenden Monat.
 */
new #[Layout('components.layouts.public')] #[Title('Ablesestatus')] class extends Component {
    public function mount(string $token): void
    {
        $secret = (string) Setting::get('status_token');

        abort_unless($secret !== '' && hash_equals($secret, $token), 404);
    }

    #[Computed]
    public function missing()
    {
        return app(Statistics::class)->metersWithoutReading(now());
    }

    #[Computed]
    public function total(): int
    {
        return Meter::query()->active()->count();
    }
}; ?>

<div>
    <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Status der Zählerablesung') }}</flux:heading>
            <flux:subheading>
                {{ __('Diese Zähler müssen im :month noch abgelesen werden (am besten bis zum :day. des Monats).', ['month' => now()->locale(app()->getLocale())->isoFormat('MMMM'), 'day' => Setting::get('reading_deadline_day')]) }}
            </flux:subheading>
        </div>
        <flux:badge size="lg" :color="$this->missing->isEmpty() ? 'green' : 'amber'">
            {{ $this->total - $this->missing->count() }} / {{ $this->total }} {{ __('abgelesen') }}
        </flux:badge>
    </div>

    @if ($this->missing->isEmpty())
        <flux:callout icon="check-circle" color="green" :heading="__('Alle Zähler sind für diesen Monat abgelesen.')" />
    @else
        <div class="grid gap-3 sm:grid-cols-2">
            @foreach ($this->missing as $i => $meter)
                <flux:card class="flex items-center justify-between gap-3" wire:key="missing-{{ $meter->id }}">
                    <div class="min-w-0">
                        <div class="font-semibold">{{ $i + 1 }}. {{ $meter->number }}</div>
                        <div class="truncate text-sm text-zinc-500">{{ $meter->tenant?->name ?? __('Kein Mieter') }} · {{ $meter->location }}</div>
                        @if ($meter->tenant?->phone)
                            <a href="tel:{{ $meter->tenant->phone }}" class="text-sm text-emerald-600">{{ $meter->tenant->phone }}</a>
                        @endif
                    </div>
                    <flux:button size="sm" variant="primary" :href="$meter->tenantUrl()">{{ __('Ablesen') }}</flux:button>
                </flux:card>
            @endforeach
        </div>
    @endif
</div>

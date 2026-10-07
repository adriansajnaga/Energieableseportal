<?php

use App\Livewire\Concerns\WithWorkingMonth;
use App\Services\ConsumptionTree;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Verbrauchsverteilung')] class extends Component {
    use WithWorkingMonth;

    #[Computed]
    public function roots()
    {
        return app(ConsumptionTree::class)->build($this->period());
    }
}; ?>

<div>
    <x-page-header :title="__('Verbrauchsverteilung')" :subtitle="__('Stromverbrauch je Abzweig und Zähler im Monat mit Differenzen zum vorgeschalteten Zähler')">
        <flux:button icon="arrow-left" variant="ghost" :href="route('settlements.index', ['monat' => $month])" wire:navigate>{{ __('Abrechnung') }}</flux:button>
        <x-month-switcher :period="$this->period()" />
    </x-page-header>

    <x-consumption-tree :roots="$this->roots" />

    @can('manage')
        <div class="mt-4">
            <flux:link :href="route('analyzer.schema', ['monat' => $month])" wire:navigate class="text-sm">{{ __('Leitungsschema bearbeiten') }} →</flux:link>
        </div>
    @endcan
</div>

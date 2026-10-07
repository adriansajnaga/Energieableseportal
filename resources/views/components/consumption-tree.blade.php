{{-- Verbrauchsverteilung eines Monats: je Hauptzähler Kennzahlen und der Baum der Abzweige und Zähler. --}}
@props(['roots'])

@if ($roots->isEmpty())
    <flux:callout icon="information-circle" :text="__('In diesem Monat waren keine Stromzähler eingebaut.')" />
@else
    <div class="space-y-8">
        @foreach ($roots as $root)
            <flux:card class="space-y-6" wire:key="tree-{{ $root['key'] }}">
                @if ($root['meter'])
                    @php
                        $worst = \App\Services\ConsumptionTree::flatten($root['children'])
                            ->filter(fn ($n) => $n['meter']?->is_analyzer && $n['difference'] !== null)
                            ->sortByDesc('difference')
                            ->first();
                    @endphp
                    <div class="grid gap-4 border-b border-zinc-200 pb-5 sm:grid-cols-2 xl:grid-cols-4 dark:border-zinc-700">
                        <div>
                            <flux:text size="sm">{{ __('Hauptzähler :number', ['number' => $root['meter']->number]) }}</flux:text>
                            <flux:heading size="lg">{{ $root['kwh'] !== null ? number_format($root['kwh'], 0, ',', '.').' kWh' : '–' }}</flux:heading>
                            <flux:text size="sm">{{ \App\Services\ConsumptionTree::sourceLabel($root['source']) }}</flux:text>
                        </div>
                        <div>
                            <flux:text size="sm">{{ __('Summe der Zähler darunter') }}</flux:text>
                            <flux:heading size="lg">{{ $root['children_kwh'] !== null ? number_format($root['children_kwh'], 0, ',', '.').' kWh' : '–' }}</flux:heading>
                        </div>
                        <div>
                            <flux:text size="sm">{{ __('Differenz (Verluste / nicht erfasst)') }}</flux:text>
                            <flux:heading size="lg">
                                @if ($root['difference'] !== null)
                                    {{ number_format($root['difference'], 0, ',', '.') }} kWh
                                    <flux:badge size="sm" :color="\App\Services\ConsumptionTree::differenceColor($root['percent'])">{{ $root['percent'] !== null ? number_format($root['percent'], 1, ',', '.').' %' : '–' }}</flux:badge>
                                @else
                                    –
                                @endif
                            </flux:heading>
                        </div>
                        <div>
                            <flux:text size="sm">{{ __('Größte Differenz an einem Abzweig') }}</flux:text>
                            @if ($worst)
                                <flux:heading size="lg">{{ $worst['meter']->number }}: {{ number_format($worst['difference'], 0, ',', '.') }} kWh</flux:heading>
                                <flux:text size="sm">{{ $worst['meter']->location }} @if ($worst['percent'] !== null) · {{ number_format($worst['percent'], 1, ',', '.') }} % @endif</flux:text>
                            @else
                                <flux:heading size="lg">–</flux:heading>
                                <flux:text size="sm">{{ __('Noch keine Analysator-Messpunkte mit Zählern dahinter.') }}</flux:text>
                            @endif
                        </div>
                    </div>
                @endif

                <x-consumption-node :node="$root" />
            </flux:card>
        @endforeach

        <flux:text size="sm">
            {{ __('Verbrauch vom 1. bis zum 1. des Folgemonats. gemessen = Ablesung am Stichtag, interpoliert = zwischen zwei Ablesungen, hochgerechnet = aus dem letzten Tagesverbrauch, abgerechnet = kWh aus der Monatsabrechnung.') }}
        </flux:text>
    </div>
@endif

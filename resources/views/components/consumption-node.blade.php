{{-- Ein Knoten der Verbrauchsverteilung mit seinen Unterzählern (rekursiv). --}}
@props(['node'])

@php($meter = $node['meter'])

<div>
    <div @class([
        'flex flex-wrap items-center justify-between gap-x-4 gap-y-1 rounded-lg border px-3 py-2',
        'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => $meter,
        'border-dashed border-zinc-300 dark:border-zinc-600' => ! $meter,
    ])>
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                @if ($meter)
                    <a href="{{ route('meters.show', $meter) }}" wire:navigate class="font-semibold hover:underline">{{ $meter->number }}</a>
                    @if ($meter->is_main)
                        <flux:badge size="sm" color="amber">{{ __('Hauptzähler') }}</flux:badge>
                    @elseif ($meter->is_analyzer)
                        <flux:badge size="sm" color="violet">{{ __('Analysator') }}</flux:badge>
                    @endif
                    @if ($node['removed'])
                        <flux:badge size="sm" color="zinc">{{ __('ausgebaut :date', ['date' => $meter->removed_on->format('d.m.')]) }}</flux:badge>
                    @elseif ($node['not_installed'] && $meter->installed_on)
                        <flux:badge size="sm" color="zinc">{{ __('eingebaut erst :date', ['date' => $meter->installed_on->format('d.m.Y')]) }}</flux:badge>
                    @elseif ($node['installed'])
                        <flux:badge size="sm" color="zinc">{{ __('eingebaut :date', ['date' => $meter->installed_on->format('d.m.')]) }}</flux:badge>
                    @endif
                @else
                    <span class="font-semibold">{{ $node['label'] }}</span>
                @endif
            </div>
            @if ($meter)
                <div class="truncate text-xs text-zinc-500">
                    {{ collect([$meter->tenant?->name, $meter->location])->filter()->join(' · ') ?: '–' }}
                    @if ($meter->factor > 1) · {{ __('Faktor') }} {{ $meter->factor }} @endif
                </div>
            @endif
        </div>
        @if ($meter)
            <div class="text-end">
                <div class="font-semibold tabular-nums">{{ $node['kwh'] !== null ? number_format($node['kwh'], 0, ',', '.').' kWh' : '–' }}</div>
                <div class="text-xs text-zinc-500">
                    @if ($node['source'] === \App\Services\ConsumptionTree::NO_INVOICE)
                        @can('view-finance')
                            <a href="{{ route('prices.index') }}" wire:navigate class="text-amber-600 hover:underline">{{ __('Versorgerrechnung fehlt – unter Strompreise erfassen') }}</a>
                        @else
                            <span class="text-amber-600">{{ __('Versorgerrechnung fehlt') }}</span>
                        @endcan
                    @else
                        {{ \App\Services\ConsumptionTree::sourceLabel($node['source']) }}
                    @endif
                </div>
            </div>
        @endif
    </div>

    @if ($node['children']->isNotEmpty())
        <div class="ms-4 mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500">
            <span>{{ __('Summe darunter: :kwh kWh', ['kwh' => number_format($node['children_kwh'], 0, ',', '.')]) }}</span>
            @if ($node['difference'] !== null)
                <flux:badge size="sm" :color="\App\Services\ConsumptionTree::differenceColor($node['percent'])">
                    {{ __('Differenz') }}: {{ number_format($node['difference'], 0, ',', '.') }} kWh
                    @if ($node['percent'] !== null) ({{ number_format($node['percent'], 1, ',', '.') }} %) @endif
                </flux:badge>
            @elseif ($meter && ! $meter->is_main && ($node['partial'] || $node['kwh'] === null))
                <span>{{ __('Differenz erst für einen ganzen Monat mit Messung') }}</span>
            @endif
            @if ($node['children_missing'])
                <span class="text-amber-600">{{ trans_choice('{1} 1 Zähler ohne Daten – Differenz unvollständig|[2,*] :count Zähler ohne Daten – Differenz unvollständig', $node['children_missing']) }}</span>
            @endif
        </div>

        <div class="ms-3 mt-2 space-y-2 border-s-2 border-zinc-200 ps-4 dark:border-zinc-700">
            @foreach ($node['children'] as $child)
                <x-consumption-node :node="$child" />
            @endforeach
        </div>
    @endif
</div>

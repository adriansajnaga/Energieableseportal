{{-- Ein Knoten der Verbrauchsverteilung mit seinen Unterzählern (rekursiv). --}}
@props(['node'])

@php
    $meter = $node['meter'];
    $children = $node['children'];
@endphp

<div>
    <div @class([
        'rounded-xl border-2 px-4 py-3 shadow-xs',
        'border-amber-300 bg-amber-50 dark:border-amber-700/70 dark:bg-amber-950/30' => $meter?->is_main,
        'border-violet-300 bg-violet-50 dark:border-violet-700/70 dark:bg-violet-950/30' => $meter?->is_analyzer,
        'border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900' => $meter && ! $meter->is_main && ! $meter->is_analyzer,
        'border-dashed border-zinc-300 bg-transparent dark:border-zinc-600' => ! $meter,
    ])>
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    @if ($meter)
                        <a href="{{ route('meters.show', $meter) }}" wire:navigate class="text-base font-semibold hover:underline">{{ $meter->number }}</a>
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
                        <span class="text-base font-semibold">{{ $node['label'] }}</span>
                    @endif
                </div>
                @if ($meter)
                    <div class="mt-0.5 truncate text-sm text-zinc-500 dark:text-zinc-400">
                        {{ collect([$meter->tenant?->name, $meter->location])->filter()->join(' · ') ?: '–' }}
                        @if ($meter->factor > 1) · {{ __('Faktor') }} {{ $meter->factor }} @endif
                    </div>
                @endif
            </div>
            @if ($meter)
                <div class="text-end">
                    <div class="text-lg font-semibold tabular-nums">{{ $node['kwh'] !== null ? number_format($node['kwh'], 0, ',', '.').' kWh' : '–' }}</div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
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

        @if ($children->isNotEmpty())
            <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-zinc-200 pt-2 text-sm text-zinc-600 dark:border-zinc-700 dark:text-zinc-400">
                <span>{{ __('Summe darunter: :kwh kWh', ['kwh' => number_format($node['children_kwh'], 0, ',', '.')]) }}</span>
                @if ($node['difference'] !== null)
                    <flux:badge :color="\App\Services\ConsumptionTree::differenceColor($node['percent'])">
                        {{ __('Differenz') }}: {{ number_format($node['difference'], 0, ',', '.') }} kWh
                        @if ($node['percent'] !== null) ({{ number_format($node['percent'], 1, ',', '.') }} %) @endif
                    </flux:badge>
                @elseif ($meter && ! $meter->is_main && ($node['partial'] || $node['kwh'] === null))
                    <span class="text-xs">{{ __('Differenz erst für einen ganzen Monat mit Messung') }}</span>
                @endif
                @if ($node['children_missing'])
                    <span class="text-amber-600">{{ trans_choice('{1} 1 Zähler ohne Daten – Differenz unvollständig|[2,*] :count Zähler ohne Daten – Differenz unvollständig', $node['children_missing']) }}</span>
                @endif
            </div>
        @endif
    </div>

    @if ($children->isNotEmpty())
        {{-- Leitungen: senkrechte Linie bis zum letzten Abzweig, waagrechte Linie zu jedem Zähler. --}}
        <div class="ms-3 sm:ms-7">
            @foreach ($children as $child)
                <div @class([
                    'relative pt-4 ps-6 sm:ps-10',
                    'before:absolute before:start-0 before:top-0 before:w-[3px] before:rounded-full before:bg-zinc-300 dark:before:bg-zinc-600',
                    'after:absolute after:start-0 after:top-[50px] after:h-[3px] after:w-6 sm:after:w-10 after:rounded-full after:bg-zinc-300 dark:after:bg-zinc-600',
                    'before:bottom-0' => ! $loop->last,
                    'before:h-[51px]' => $loop->last,
                ])>
                    <x-consumption-node :node="$child" />
                </div>
            @endforeach
        </div>
    @endif
</div>

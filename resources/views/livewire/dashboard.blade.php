<?php

use App\Enums\ReadingStatus;
use App\Livewire\Concerns\WithWorkingMonth;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Settlement;
use App\Services\SettlementService;
use App\Services\Statistics;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Dashboard')] class extends Component {
    use WithWorkingMonth;

    #[Computed]
    public function readingStatus(): array
    {
        $total = Meter::query()->active()->count();
        $missing = app(Statistics::class)->metersWithoutReading(now())->count();

        return ['total' => $total, 'done' => $total - $missing];
    }

    #[Computed]
    public function settlementStatus(): array
    {
        $candidates = app(SettlementService::class)->candidates($this->period());

        return [
            'total' => $candidates->count(),
            'settled' => $candidates->where(fn ($c) => $c->status() === 'settled')->count(),
            'ready' => $candidates->filter->isReady()->count(),
            'net' => (float) Settlement::query()->effective()->whereDate('period', $this->period()->toDateString())->sum('net_amount'),
        ];
    }

    #[Computed]
    public function pendingReadings()
    {
        return Reading::query()->where('status', ReadingStatus::Pending)->with('meter.tenant')->latest('read_on')->limit(5)->get();
    }

    #[Computed]
    public function consumptionChart(): array
    {
        $totals = app(Statistics::class)->monthlyTotals($this->period()->subMonthsNoOverflow(11), $this->period());

        return [
            'type' => 'bar',
            'data' => [
                'labels' => $totals->keys()->map(fn ($m) => \Carbon\Carbon::createFromFormat('!Y-m', $m)->locale(app()->getLocale())->isoFormat('MMM YY'))->values(),
                'datasets' => [
                    ['label' => __('Verbrauch (kWh)'), 'data' => $totals->pluck('kwh')->values(), 'backgroundColor' => '#10b981', 'borderRadius' => 4, 'yAxisID' => 'y'],
                    ['label' => __('Entgelt netto (€)'), 'data' => $totals->pluck('net')->values(), 'type' => 'line', 'borderColor' => '#6366f1', 'backgroundColor' => '#6366f1', 'tension' => 0.3, 'yAxisID' => 'y1'],
                ],
            ],
            'options' => ['scales' => ['y1' => ['position' => 'right', 'beginAtZero' => true, 'grid' => ['display' => false]]]],
        ];
    }

    #[Computed]
    public function lossChart(): array
    {
        $comparison = app(Statistics::class)->mainMeterComparison($this->period()->subMonthsNoOverflow(11), $this->period(), onlyActive: true);
        $colors = ['#f59e0b', '#ef4444', '#3b82f6', '#8b5cf6'];
        $labels = collect();

        $datasets = $comparison->values()->map(function ($row, $i) use ($colors, &$labels) {
            $labels = $row['months']->keys()->map(fn ($m) => \Carbon\Carbon::createFromFormat('!Y-m', $m)->locale(app()->getLocale())->isoFormat('MMM YY'))->values();

            return [
                'label' => __('Abweichung :meter (%)', ['meter' => $row['meter']->number]),
                'data' => $row['months']->pluck('percent')->values(),
                'borderColor' => $colors[$i % count($colors)],
                'backgroundColor' => $colors[$i % count($colors)],
                'tension' => 0.3,
                'spanGaps' => true,
            ];
        });

        return ['type' => 'line', 'data' => ['labels' => $labels, 'datasets' => $datasets], 'options' => ['scales' => ['y' => ['beginAtZero' => false]]]];
    }
}; ?>

<div>
    <x-page-header :title="__('Dashboard')" :subtitle="__('Überblick über Ablesungen und Abrechnungen')">
        <x-month-switcher :period="$this->period()" />
    </x-page-header>

    @if (! extension_loaded('fileinfo') && Gate::allows('manage'))
        <flux:callout icon="exclamation-triangle" color="red" class="mb-4"
            :heading="__('PHP-Erweiterung „fileinfo“ fehlt')"
            :text="__('Fotos der Zählerstände können nicht hochgeladen werden. Bitte beim Hoster aktivieren lassen (PHP :version).', ['version' => PHP_VERSION])" />
    @endif

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <flux:card>
            <flux:subheading>{{ __('Ablesungen :month', ['month' => now()->format('m/Y')]) }}</flux:subheading>
            <flux:heading size="xl" class="mt-2">{{ $this->readingStatus['done'] }} / {{ $this->readingStatus['total'] }}</flux:heading>
            <div class="mt-3 h-2 overflow-hidden rounded bg-zinc-100 dark:bg-zinc-700">
                <div class="h-full bg-emerald-500" style="width: {{ $this->readingStatus['total'] ? round($this->readingStatus['done'] / $this->readingStatus['total'] * 100) : 0 }}%"></div>
            </div>
            <flux:link :href="route('readings.status')" wire:navigate class="mt-3 inline-block text-sm">{{ __('Ablesestatus') }} →</flux:link>
        </flux:card>

        <flux:card>
            <flux:subheading>{{ __('Zu prüfende Ablesungen') }}</flux:subheading>
            <flux:heading size="xl" class="mt-2 {{ $this->pendingReadings->isNotEmpty() ? 'text-amber-600' : '' }}">
                {{ \App\Models\Reading::where('status', 'pending')->count() }}
            </flux:heading>
            <flux:link :href="route('readings.index', ['status' => 'pending'])" wire:navigate class="mt-3 inline-block text-sm">{{ __('Prüfen') }} →</flux:link>
        </flux:card>

        <flux:card>
            <flux:subheading>{{ __('Abgerechnet') }} {{ $this->period()->format('m/Y') }}</flux:subheading>
            <flux:heading size="xl" class="mt-2">{{ $this->settlementStatus['settled'] }} / {{ $this->settlementStatus['total'] }}</flux:heading>
            <flux:text class="mt-3 text-sm">{{ __(':count bereit zur Abrechnung', ['count' => $this->settlementStatus['ready']]) }}</flux:text>
        </flux:card>

        <flux:card>
            <flux:subheading>{{ __('Entgelt netto') }} {{ $this->period()->format('m/Y') }}</flux:subheading>
            <flux:heading size="xl" class="mt-2">{{ number_format($this->settlementStatus['net'], 2, ',', '.') }} €</flux:heading>
            @can('manage')
                <flux:link :href="route('settlements.index', ['monat' => $month])" wire:navigate class="mt-3 inline-block text-sm">{{ __('Zur Abrechnung') }} →</flux:link>
            @endcan
        </flux:card>
    </div>

    <div class="mt-6 grid gap-4 xl:grid-cols-2">
        <flux:card>
            <flux:heading>{{ __('Abgerechneter Verbrauch der letzten 12 Monate') }}</flux:heading>
            <div class="mt-4 h-72" wire:ignore wire:key="consumption-{{ $month }}">
                <canvas x-data x-init="energieChart($el, @js($this->consumptionChart))"></canvas>
            </div>
        </flux:card>

        <flux:card>
            <flux:heading>{{ __('Abweichung Hauptzähler zu Unterzählern') }}</flux:heading>
            <flux:subheading>{{ __('Differenz zwischen Versorgerrechnung und abgerechneten Unterzählern') }}</flux:subheading>
            <div class="mt-4 h-64" wire:ignore wire:key="loss-{{ $month }}">
                <canvas x-data x-init="energieChart($el, @js($this->lossChart))"></canvas>
            </div>
        </flux:card>
    </div>

    @if ($this->pendingReadings->isNotEmpty())
        <flux:card class="mt-6">
            <flux:heading>{{ __('Zu prüfende Ablesungen') }}</flux:heading>
            <ul class="mt-3 divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->pendingReadings as $reading)
                    <li class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm">
                        <span><strong>{{ $reading->meter->number }}</strong> · {{ $reading->meter->tenant?->name }} · {{ $reading->read_on->format('d.m.Y') }} · {{ number_format($reading->value, 0, ',', '.') }} kWh</span>
                        <span class="text-amber-600">{{ $reading->check_note }}</span>
                    </li>
                @endforeach
            </ul>
        </flux:card>
    @endif
</div>

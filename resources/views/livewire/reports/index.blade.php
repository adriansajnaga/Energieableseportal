<?php

use App\Livewire\Concerns\WithWorkingMonth;
use App\Services\Statistics;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Berichte')] class extends Component {
    use WithWorkingMonth;

    #[Computed]
    public function comparison()
    {
        $year = $this->period()->startOfYear();

        return app(Statistics::class)->mainMeterComparison($year, $year->endOfYear());
    }
}; ?>

<div>
    <x-page-header :title="__('Berichte')" :subtitle="__('PDF-Berichte und Auswertungen')">
        <x-month-switcher :period="$this->period()" />
    </x-page-header>

    @php($year = $this->period()->year)
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ([
            ['icon' => 'qr-code', 'title' => __('QR-Code-Liste Hausmeister'), 'text' => __('Alle aktiven Zähler mit QR-Code, 5 pro Seite.'), 'url' => route('pdf.qr-list')],
            ['icon' => 'tag', 'title' => __('QR-Code-Etiketten'), 'text' => __('Ein Etikett pro Seite zum Aufkleben am Zähler.'), 'url' => route('pdf.qr-labels')],
            ['icon' => 'clipboard-document-check', 'title' => __('Ablesestatus'), 'text' => __('Zähler, die im laufenden Monat noch fehlen.'), 'url' => route('readings.status'), 'navigate' => true],
            ['icon' => 'bolt', 'title' => __('Bericht Hauptzähler :month', ['month' => $this->period()->format('m/Y')]), 'text' => __('Versorgerrechnung und abgerechnete Unterzähler des Monats.'), 'url' => route('pdf.main-meters', $month)],
            ['icon' => 'chart-bar', 'title' => __('Verbrauchsbericht :year', ['year' => $year]), 'text' => __('Jahresübersicht je Mieter und Zähler.'), 'url' => route('pdf.consumption', $year)],
            ['icon' => 'scale', 'title' => __('Abweichungsbericht :year', ['year' => $year]), 'text' => __('Hauptzähler laut Rechnung gegen Summe der Unterzähler.'), 'url' => route('pdf.difference', $year)],
        ] as $report)
            <flux:card class="flex flex-col gap-2">
                <div class="flex items-center gap-2">
                    <flux:icon :name="$report['icon']" class="text-emerald-600" />
                    <flux:heading>{{ $report['title'] }}</flux:heading>
                </div>
                <flux:text class="flex-1">{{ $report['text'] }}</flux:text>
                <div>
                    @if ($report['navigate'] ?? false)
                        <flux:button size="sm" :href="$report['url']" wire:navigate>{{ __('Öffnen') }}</flux:button>
                    @else
                        <flux:button size="sm" icon="document-arrow-down" :href="$report['url']" target="_blank">{{ __('PDF öffnen') }}</flux:button>
                    @endif
                </div>
            </flux:card>
        @endforeach
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Abweichung Hauptzähler :year', ['year' => $year]) }}</flux:heading>
    @foreach ($this->comparison as $row)
        <flux:card class="mt-4">
            <flux:heading>{{ $row['meter']->number }} <span class="font-normal text-zinc-500">{{ $row['meter']->location }}</span></flux:heading>
            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('Monat') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('laut Rechnung') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('laut Unterzählern') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Abweichung') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($row['months'] as $key => $m)
                        <flux:table.row :key="$row['meter']->id.$key">
                            <flux:table.cell>{{ \Carbon\Carbon::createFromFormat('!Y-m', $key)->locale(app()->getLocale())->isoFormat('MMMM') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $m['supplier'] ? number_format($m['supplier'], 0, ',', '.').' kWh' : '–' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $m['submeters'] ? number_format($m['submeters'], 0, ',', '.').' kWh' : '–' }}</flux:table.cell>
                            <flux:table.cell align="end" class="{{ ($m['percent'] ?? 0) > 10 ? 'text-red-600' : '' }}">
                                @if ($m['supplier'] && $m['submeters'])
                                    {{ number_format($m['difference'], 0, ',', '.') }} kWh ({{ number_format($m['percent'], 1, ',', '.') }} %)
                                @else
                                    –
                                @endif
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @endforeach
</div>

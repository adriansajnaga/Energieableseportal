<?php

use App\Models\ElectricityPrice;
use App\Models\Meter;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Title('Strompreise')] class extends Component {
    #[Url(as: 'jahr')]
    public int $year = 0;

    public ?int $meterId = null;
    public string $monthValue = '';
    public string $supplier_invoice_number = '';
    public string $consumption_kwh = '';
    public string $net_amount = '';
    public string $net_price = '';

    public function mount(): void
    {
        $this->year = $this->year ?: Auth::user()->workingMonth()->year;
    }

    #[Computed]
    public function mainMeters()
    {
        return Meter::query()->main()->electricity()->active()->orderBy('number')->get();
    }

    #[Computed]
    public function prices()
    {
        return ElectricityPrice::query()
            ->whereYear('month', $this->year)
            ->get()
            ->keyBy(fn ($p) => $p->meter_id.'-'.$p->month->format('m'));
    }

    public function edit(int $meterId, int $month): void
    {
        Gate::authorize('manage');
        $this->resetValidation();
        $this->meterId = $meterId;
        $this->monthValue = CarbonImmutable::create($this->year, $month, 1)->toDateString();

        $price = $this->prices->get($meterId.'-'.sprintf('%02d', $month));
        $this->supplier_invoice_number = (string) $price?->supplier_invoice_number;
        $this->consumption_kwh = (string) ($price?->consumption_kwh ?? '');
        $this->net_amount = (string) ($price?->net_amount ?? '');
        $this->net_price = (string) ($price?->net_price ?? '');

        Flux::modal('price-form')->show();
    }

    // Preis = Rechnungsbetrag / Verbrauch, kann aber überschrieben werden.
    public function updated($property): void
    {
        if (in_array($property, ['consumption_kwh', 'net_amount']) && (float) $this->consumption_kwh > 0 && is_numeric($this->net_amount)) {
            $this->net_price = (string) round((float) $this->net_amount / (float) $this->consumption_kwh, 5);
        }
    }

    public function save(): void
    {
        Gate::authorize('manage');

        $data = $this->validate([
            'supplier_invoice_number' => ['nullable', 'string', 'max:255'],
            'consumption_kwh' => ['required', 'numeric', 'min:0'],
            'net_amount' => ['required', 'numeric', 'min:0'],
            'net_price' => ['required', 'numeric', 'gt:0', 'lt:10'],
        ]);

        ElectricityPrice::updateOrCreate(
            ['meter_id' => $this->meterId, 'month' => $this->monthValue],
            [...$data, 'updated_by' => Auth::id()],
        );

        unset($this->prices);
        Flux::modal('price-form')->close();
        Flux::toast(__('Strompreis gespeichert.'), variant: 'success');
    }
}; ?>

<div>
    <x-page-header :title="__('Strompreise')" :subtitle="__('Rechnungen des Versorgers je Hauptzähler und Monat')">
        <div class="flex items-center gap-1">
            <flux:button size="sm" variant="ghost" icon="chevron-left" wire:click="$set('year', {{ $year - 1 }})" />
            <span class="w-16 text-center font-semibold">{{ $year }}</span>
            <flux:button size="sm" variant="ghost" icon="chevron-right" wire:click="$set('year', {{ $year + 1 }})" />
        </div>
    </x-page-header>

    @forelse ($this->mainMeters as $meter)
        <flux:card class="mb-6">
            <flux:heading>{{ $meter->number }} <span class="font-normal text-zinc-500">{{ $meter->location }}</span></flux:heading>
            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('Monat') }}</flux:table.column>
                    <flux:table.column>{{ __('Rechnungsnr.') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Verbrauch') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Betrag netto') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Strompreis') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach (range(1, 12) as $m)
                        @php($price = $this->prices->get($meter->id.'-'.sprintf('%02d', $m)))
                        <flux:table.row :key="$meter->id.'-'.$m">
                            <flux:table.cell>{{ \Carbon\Carbon::create($year, $m, 1)->locale(app()->getLocale())->isoFormat('MMMM') }}</flux:table.cell>
                            <flux:table.cell>{{ $price?->supplier_invoice_number ?? '' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $price ? number_format($price->consumption_kwh, 0, ',', '.').' kWh' : '' }}</flux:table.cell>
                            <flux:table.cell align="end">{{ $price ? number_format($price->net_amount, 2, ',', '.').' €' : '' }}</flux:table.cell>
                            <flux:table.cell align="end">
                                @if ($price)
                                    <strong>{{ number_format($price->net_price, 5, ',', '.') }} €/kWh</strong>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('fehlt') }}</flux:badge>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                @can('manage')
                                    <flux:button size="sm" variant="ghost" :icon="$price ? 'pencil-square' : 'plus'" wire:click="edit({{ $meter->id }}, {{ $m }})" />
                                @endcan
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    @empty
        <flux:callout icon="information-circle" :text="__('Es gibt noch keine Hauptzähler. Markieren Sie einen Zähler als Hauptzähler, um Strompreise zu erfassen.')" />
    @endforelse

    <flux:modal name="price-form" class="md:w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ __('Strompreis') }} {{ $monthValue ? \Carbon\Carbon::parse($monthValue)->format('m/Y') : '' }}</flux:heading>
            <flux:input wire:model="supplier_invoice_number" :label="__('Rechnungsnummer des Versorgers')" />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model.live.debounce.400ms="consumption_kwh" :label="__('Verbrauch (kWh)')" type="number" step="0.01" required />
                <flux:input wire:model.live.debounce.400ms="net_amount" :label="__('Rechnungsbetrag netto (€)')" type="number" step="0.01" required />
            </div>
            <flux:input wire:model="net_price" :label="__('Strompreis netto (€/kWh)')" :description="__('Wird aus Betrag / Verbrauch berechnet und kann angepasst werden.')" type="number" step="0.00001" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

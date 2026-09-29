<?php

use App\Livewire\Concerns\WithWorkingMonth;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Tenant;
use App\Services\SettlementCandidate;
use App\Services\SettlementService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Abrechnen')] class extends Component {
    use WithWorkingMonth;

    #[Locked]
    public int $meterId;

    #[Locked]
    public int $tenantId;

    public string $sampleId = '';
    public string $priceFactor = '';
    public bool $invoice = true;

    public function mount(Meter $meter, Tenant $tenant): void
    {
        $this->meterId = $meter->id;
        $this->tenantId = $tenant->id;
        $this->priceFactor = (string) $tenant->effectivePriceFactor();
        $this->sampleId = (string) $this->candidate?->defaultSample()?->id;
    }

    #[Computed]
    public function candidate(): ?SettlementCandidate
    {
        return app(SettlementService::class)->candidate($this->period(), $this->meterId, $this->tenantId);
    }

    #[Computed]
    public function preview()
    {
        $sample = $this->candidate?->samples->firstWhere('id', (int) $this->sampleId);

        if (! $sample || ! is_numeric(str_replace(',', '.', $this->priceFactor))) {
            return null;
        }

        try {
            return app(SettlementService::class)->preview($this->candidate, $sample, $this->priceFactor);
        } catch (Throwable $e) {
            $this->addError('sampleId', $e->getMessage());

            return null;
        }
    }

    public function save(SettlementService $service)
    {
        $this->validate([
            'sampleId' => ['required', 'integer'],
            'priceFactor' => ['required', 'numeric', 'min:0.5', 'max:5'],
        ]);

        try {
            $settlement = $service->settle(
                $this->candidate,
                Reading::findOrFail($this->sampleId),
                $this->priceFactor,
                $this->invoice,
                Auth::user(),
            );
        } catch (RuntimeException|InvalidArgumentException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return null;
        }

        Flux::toast(__('Abgerechnet: :number', ['number' => $settlement->formattedNumber()]), variant: 'success');

        return $this->redirectRoute('settlements.index', ['monat' => $this->month], navigate: true);
    }
}; ?>

<div class="max-w-4xl">
    @php($c = $this->candidate)
    @php($p = $this->preview)
    @php($kwh = fn ($v) => number_format($v, 0, ',', '.'))

    <x-page-header :title="__('Abrechnen')" :subtitle="$c ? $c->tenant->name.' · '.$c->meter->number.' · '.$this->period()->format('m/Y') : ''">
        <flux:button icon="arrow-left" variant="ghost" :href="route('settlements.index', ['monat' => $month])" wire:navigate>{{ __('Zurück') }}</flux:button>
    </x-page-header>

    @if (! $c || ! in_array($c->status(), [SettlementCandidate::READY], true))
        <flux:callout icon="exclamation-triangle" color="amber" :heading="__('Abrechnung nicht möglich')" :text="$c?->statusLabel() ?? __('Zähler ist dem Mieter in diesem Monat nicht zugeordnet.')" />
    @else
        <div class="grid gap-4 md:grid-cols-2">
            <flux:card>
                <flux:subheading>{{ __('1. Anfangsstand') }}</flux:subheading>
                <flux:heading size="xl" class="mt-1">{{ $kwh($c->startReading->value) }} kWh</flux:heading>
                <flux:text>{{ $c->startReading->read_on->format('d.m.Y') }} · {{ $c->startReading->source->label() }}</flux:text>
            </flux:card>
            <flux:card>
                <flux:select wire:model.live="sampleId" :label="__('2. Spätere Ablesung')">
                    @foreach ($c->samples as $sample)
                        <flux:select.option :value="$sample->id">{{ $sample->read_on->format('d.m.Y') }} – {{ $kwh($sample->value) }} kWh ({{ $sample->source->label() }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="sampleId" />
            </flux:card>
        </div>

        <div class="mt-4 grid gap-4 md:grid-cols-2">
            <flux:card>
                <flux:heading>{{ __('Berechnung') }}</flux:heading>
                @if ($p)
                    <dl class="mt-3 grid grid-cols-[1fr_auto] gap-y-2 text-sm">
                        <dt class="text-zinc-500">{{ __('Verbrauch zwischen den Ablesungen') }}</dt><dd class="text-end">{{ $kwh($p->consumptionBetweenReadings) }} kWh</dd>
                        <dt class="text-zinc-500">{{ __('Tage zwischen den Ablesungen') }}</dt><dd class="text-end">{{ $p->daysBetweenReadings }}</dd>
                        <dt class="text-zinc-500">{{ __('Ø Tagesverbrauch') }}</dt><dd class="text-end">{{ number_format($p->dailyConsumption, 2, ',', '.') }} kWh</dd>
                        <dt class="text-zinc-500">{{ __('Tage im Abrechnungszeitraum') }}</dt><dd class="text-end">{{ $p->daysInPeriod }}</dd>
                        <dt class="font-medium">{{ __('Verbrauch im Zeitraum') }} @if ($p->isExtrapolated) <flux:badge size="sm" color="sky">{{ __('hochgerechnet') }}</flux:badge> @endif</dt><dd class="text-end font-medium">{{ $kwh($p->consumptionKwh) }} kWh</dd>
                        <dt class="text-zinc-500">{{ __('Berechneter Stand am :date', ['date' => $p->endsOn->format('d.m.Y')]) }}</dt><dd class="text-end">{{ $kwh($p->endValue) }} kWh</dd>
                    </dl>
                @endif
            </flux:card>

            <flux:card class="space-y-4">
                <flux:heading>{{ __('Preis') }}</flux:heading>
                <flux:text>{{ __('Strompreis Hauptzähler') }}: {{ number_format((float) $c->price->net_price, 5, ',', '.') }} €/kWh</flux:text>
                <flux:input wire:model.live.debounce.400ms="priceFactor" :label="__('Preisfaktor')" type="number" step="0.001" min="0.5" />
                @if ($p)
                    <dl class="grid grid-cols-[1fr_auto] gap-y-2 text-sm">
                        <dt class="text-zinc-500">{{ __('Preis für den Mieter') }}</dt><dd class="text-end">{{ number_format($p->unitPriceCents / 100, 2, ',', '.') }} €/kWh</dd>
                        <dt class="text-zinc-500">{{ __('Abgerechnete kWh (x Zählerfaktor :factor)', ['factor' => $p->meterFactor]) }}</dt><dd class="text-end">{{ $kwh($p->billedKwh) }} kWh</dd>
                        <dt class="font-medium">{{ __('Entgelt netto') }}</dt><dd class="text-end font-medium">{{ number_format($p->netCents / 100, 2, ',', '.') }} €</dd>
                        <dt class="text-zinc-500">{{ __('USt. :rate %', ['rate' => (float) $p->vatRate]) }}</dt><dd class="text-end">{{ number_format($p->vatCents / 100, 2, ',', '.') }} €</dd>
                        <dt class="text-lg font-semibold">{{ __('Brutto') }}</dt><dd class="text-end text-lg font-semibold">{{ number_format($p->grossCents / 100, 2, ',', '.') }} €</dd>
                    </dl>
                @endif
            </flux:card>
        </div>

        <div class="mt-6 flex flex-wrap items-center justify-end gap-4">
            <flux:checkbox wire:model="invoice" :label="__('Rechnung mit Rechnungsnummer erstellen')" />
            <flux:button variant="primary" icon="check" wire:click="save" :disabled="! $p">{{ __('Abrechnen') }}</flux:button>
        </div>
    @endif
</div>

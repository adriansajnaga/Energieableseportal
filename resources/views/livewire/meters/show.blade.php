<?php

use App\Models\Meter;
use App\Models\Tenant;
use App\Services\MeterService;
use App\Support\QrCode;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Volt\Component;

new class extends Component {
    public Meter $meter;

    public string $change_tenant_id = '';
    public string $change_date = '';
    public string $change_value = '';

    public string $new_number = '';
    public string $replace_date = '';
    public string $final_value = '';
    public string $initial_value = '0';

    public function mount(Meter $meter): void
    {
        $this->meter = $meter;
    }

    public function rendering($view): void
    {
        $view->title(__('Zähler').' '.$this->meter->number);
    }

    #[Computed]
    public function readings()
    {
        return $this->meter->readings()->with('tenant')->orderByDesc('read_on')->orderByDesc('id')->limit(36)->get();
    }

    #[Computed]
    public function assignments()
    {
        return $this->meter->assignments()->with('tenant')->orderByDesc('starts_on')->get();
    }

    #[Computed]
    public function settlements()
    {
        return $this->meter->settlements()->with('tenant')->orderByDesc('period')->orderByDesc('id')->limit(24)->get();
    }

    #[Computed]
    public function tenants()
    {
        return Tenant::query()->active()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function chart(): array
    {
        $readings = $this->meter->readings()->valid()->orderBy('read_on')->get(['read_on', 'value'])
            ->unique(fn ($r) => $r->read_on->toDateString())->values();

        $daily = $readings->slice(1)->values()->map(function ($r, $i) use ($readings) {
            $prev = $readings[$i];
            $days = max(1, (int) $prev->read_on->diffInDays($r->read_on));

            return round(($r->value - $prev->value) / $days, 2);
        });

        return [
            'type' => 'line',
            'data' => [
                'labels' => $readings->slice(1)->map(fn ($r) => $r->read_on->format('d.m.y'))->values(),
                'datasets' => [[
                    'label' => __('Ø Verbrauch pro Tag (kWh)'),
                    'data' => $daily,
                    'borderColor' => '#10b981',
                    'backgroundColor' => 'rgba(16,185,129,0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ]],
            ],
        ];
    }

    public function qrSvg(): string
    {
        return QrCode::svg($this->meter->tenantUrl());
    }

    public function regenerateToken(): void
    {
        Gate::authorize('manage');
        $this->meter->update(['qr_token' => Meter::newQrToken(), 'legacy_hash' => null]);
        Flux::toast(__('Neuer QR-Code erzeugt. Bitte das Etikett am Zähler austauschen.'), variant: 'warning');
    }

    public function openTenantChange(): void
    {
        Gate::authorize('manage');
        $this->reset(['change_tenant_id', 'change_value']);
        $this->change_date = now()->toDateString();
        Flux::modal('tenant-change')->show();
    }

    public function changeTenant(MeterService $service): void
    {
        Gate::authorize('manage');

        $this->validate([
            'change_tenant_id' => ['nullable', 'exists:tenants,id'],
            'change_date' => ['required', 'date'],
            'change_value' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $service->changeTenant(
                $this->meter,
                $this->change_tenant_id ? Tenant::find($this->change_tenant_id) : null,
                CarbonImmutable::parse($this->change_date),
                (int) $this->change_value,
                Auth::user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('change_date', $e->getMessage());

            return;
        }

        $this->meter->refresh();
        unset($this->readings, $this->assignments);
        Flux::modal('tenant-change')->close();
        Flux::toast(__('Mieterwechsel gespeichert.'), variant: 'success');
    }

    public function openReplace(): void
    {
        Gate::authorize('manage');
        $this->reset(['new_number', 'final_value']);
        $this->initial_value = '0';
        $this->replace_date = now()->toDateString();
        Flux::modal('meter-replace')->show();
    }

    public function replace(MeterService $service)
    {
        Gate::authorize('manage');

        $this->validate([
            'new_number' => ['required', 'string', 'max:255'],
            'replace_date' => ['required', 'date'],
            'final_value' => ['required', 'integer', 'min:0'],
            'initial_value' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $new = $service->replace($this->meter, $this->new_number, CarbonImmutable::parse($this->replace_date), (int) $this->final_value, (int) $this->initial_value, Auth::user());
        } catch (RuntimeException $e) {
            $this->addError('replace_date', $e->getMessage());

            return null;
        }

        Flux::toast(__('Zähler ausgetauscht.'), variant: 'success');

        return $this->redirectRoute('meters.show', $new, navigate: true);
    }
}; ?>

<div>
    <x-page-header :title="__('Zähler').' '.$meter->number" :subtitle="$meter->location">
        <flux:button icon="arrow-left" variant="ghost" :href="route('meters.index')" wire:navigate>{{ __('Zurück') }}</flux:button>
        @can('manage')
            @if ($meter->is_active)
                <flux:button icon="arrows-right-left" wire:click="openTenantChange">{{ __('Mieterwechsel') }}</flux:button>
                <flux:button icon="wrench-screwdriver" wire:click="openReplace">{{ __('Zählerwechsel') }}</flux:button>
            @endif
        @endcan
    </x-page-header>

    @if ($meter->replacedBy)
        <flux:callout icon="information-circle" class="mb-4" :text="__('Dieser Zähler wurde am :date durch :number ersetzt.', ['date' => $meter->removed_on?->format('d.m.Y'), 'number' => $meter->replacedBy->number])" />
    @endif

    <div class="grid gap-4 lg:grid-cols-3">
        <flux:card class="space-y-2 text-sm">
            <flux:heading>{{ __('Stammdaten') }}</flux:heading>
            <dl class="grid grid-cols-2 gap-y-2">
                <dt class="text-zinc-500">{{ __('Mieter') }}</dt><dd>{{ $meter->tenant?->name ?? '–' }}</dd>
                <dt class="text-zinc-500">{{ __('Zählerfaktor') }}</dt><dd>{{ $meter->factor }}</dd>
                <dt class="text-zinc-500">{{ __('Typ') }}</dt><dd>{{ $meter->is_main ? __('Hauptzähler') : __('Unterzähler') }}</dd>
                <dt class="text-zinc-500">{{ __('Hauptzähler') }}</dt><dd>{{ $meter->parent?->number ?? '–' }}</dd>
                <dt class="text-zinc-500">{{ __('Eingebaut') }}</dt><dd>{{ $meter->installed_on?->format('d.m.Y') ?? '–' }}</dd>
                <dt class="text-zinc-500">{{ __('Aktiv') }}</dt><dd>{{ $meter->is_active ? __('Ja') : __('Nein') }}</dd>
            </dl>
            @if ($meter->is_main)
                <flux:text class="pt-2">{{ __('Unterzähler') }}: {{ $meter->children()->pluck('number')->join(', ') ?: '–' }}</flux:text>
            @endif
        </flux:card>

        <flux:card class="text-center">
            <flux:heading>{{ __('QR-Code für die Ablesung') }}</flux:heading>
            <div class="mx-auto mt-3 w-44 rounded bg-white p-1">{!! $this->qrSvg() !!}</div>
            <flux:link :href="$meter->tenantUrl()" target="_blank" class="mt-2 inline-block break-all text-xs">{{ $meter->tenantUrl() }}</flux:link>
            @if ($meter->legacy_hash)
                <flux:text class="mt-2 text-xs text-amber-600">{{ __('Alter QR-Code (Altsystem) ist noch gültig.') }}</flux:text>
            @endif
            @can('manage')
                <div class="mt-3 flex flex-wrap justify-center gap-1">
                    <flux:button size="sm" icon="photo" :href="route('labels.image', [$meter, 'png'])">{{ __('Etikett PNG') }}</flux:button>
                    <flux:button size="sm" icon="photo" :href="route('labels.image', [$meter, 'jpg'])">{{ __('Etikett JPG') }}</flux:button>
                </div>
                <div class="mt-1">
                    <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="regenerateToken" wire:confirm="{{ __('Neuen QR-Code erzeugen? Der bisherige Code am Zähler wird ungültig.') }}">{{ __('Neuen Code erzeugen') }}</flux:button>
                </div>
            @endcan
        </flux:card>

        <flux:card>
            <flux:heading>{{ __('Mieterhistorie') }}</flux:heading>
            <ul class="mt-3 space-y-2 text-sm">
                @forelse ($this->assignments as $assignment)
                    <li class="flex justify-between gap-2">
                        <span>{{ $assignment->tenant->name }}</span>
                        <span class="text-zinc-500">{{ $assignment->starts_on->format('d.m.Y') }} – {{ $assignment->ends_on?->format('d.m.Y') ?? __('heute') }}</span>
                    </li>
                @empty
                    <li class="text-zinc-500">{{ __('Keine Zuordnung') }}</li>
                @endforelse
            </ul>
        </flux:card>
    </div>

    <flux:card class="mt-4">
        <flux:heading>{{ __('Verbrauchsverlauf') }}</flux:heading>
        <div class="mt-4 h-64" wire:ignore>
            <canvas x-data x-init="energieChart($el, @js($this->chart))"></canvas>
        </div>
    </flux:card>

    <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <flux:card>
            <flux:heading>{{ __('Zählerstände') }}</flux:heading>
            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('Datum') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Stand (kWh)') }}</flux:table.column>
                    <flux:table.column>{{ __('Quelle') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->readings as $reading)
                        <flux:table.row :key="$reading->id">
                            <flux:table.cell>{{ $reading->read_on->format('d.m.Y') }} @if ($reading->is_base) <flux:badge size="sm" color="sky">{{ __('Basis') }}</flux:badge> @endif</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($reading->value, 0, ',', '.') }}</flux:table.cell>
                            <flux:table.cell>{{ $reading->source->label() }}</flux:table.cell>
                            <flux:table.cell><flux:badge size="sm" :color="$reading->status->color()">{{ $reading->status->label() }}</flux:badge></flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>

        <flux:card>
            <flux:heading>{{ __('Abrechnungen') }}</flux:heading>
            <flux:table class="mt-2">
                <flux:table.columns>
                    <flux:table.column>{{ __('Monat') }}</flux:table.column>
                    <flux:table.column>{{ __('Rechnung') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('kWh') }}</flux:table.column>
                    <flux:table.column align="end">{{ __('Netto') }}</flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @foreach ($this->settlements as $settlement)
                        <flux:table.row :key="$settlement->id">
                            <flux:table.cell>{{ $settlement->period->format('m/Y') }}</flux:table.cell>
                            <flux:table.cell>
                                <flux:link :href="route('pdf.invoice', $settlement->activeCollective() ?? $settlement)" target="_blank" class="{{ $settlement->isCancelled() ? 'line-through' : '' }}">{{ $settlement->invoiceLabel() }}</flux:link>
                                @if ($settlement->type === \App\Enums\SettlementType::Cancellation) <flux:badge size="sm" color="red">{{ __('Storno') }}</flux:badge> @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($settlement->billed_kwh, 0, ',', '.') }}</flux:table.cell>
                            <flux:table.cell align="end">{{ number_format($settlement->net_amount, 2, ',', '.') }} €</flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </flux:card>
    </div>

    <flux:modal name="tenant-change" class="md:w-lg">
        <form wire:submit="changeTenant" class="space-y-5">
            <flux:heading size="lg">{{ __('Mieterwechsel') }}</flux:heading>
            <flux:text>{{ __('Die Ablesung am Stichtag ist Endstand für den bisherigen und Anfangsstand für den neuen Mieter.') }}</flux:text>
            <flux:select wire:model="change_tenant_id" :label="__('Neuer Mieter')">
                <flux:select.option value="">{{ __('– Leerstand –') }}</flux:select.option>
                @foreach ($this->tenants as $tenant)
                    <flux:select.option :value="$tenant->id">{{ $tenant->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="change_date" :label="__('Stichtag')" type="date" required />
                <flux:input wire:model="change_value" :label="__('Zählerstand (kWh)')" type="number" min="0" required />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="meter-replace" class="md:w-lg">
        <form wire:submit="replace" class="space-y-5">
            <flux:heading size="lg">{{ __('Zählerwechsel') }}</flux:heading>
            <flux:text>{{ __('Der alte Zähler wird mit dem Endstand deaktiviert, der neue übernimmt Mieter, Ort, Faktor und Hauptzähler.') }}</flux:text>
            <flux:input wire:model="new_number" :label="__('Neue Zählernummer')" required />
            <flux:input wire:model="replace_date" :label="__('Datum des Wechsels')" type="date" required />
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="final_value" :label="__('Endstand alter Zähler')" type="number" min="0" required />
                <flux:input wire:model="initial_value" :label="__('Anfangsstand neuer Zähler')" type="number" min="0" required />
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Zähler tauschen') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

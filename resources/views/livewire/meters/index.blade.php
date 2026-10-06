<?php

use App\Enums\Medium;
use App\Models\Meter;
use App\Models\Tenant;
use App\Rules\MeterValue;
use App\Services\MeterService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Zähler')] class extends Component {
    use WithPagination;

    #[Url(as: 'suche')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = 'active';

    /** '' = alle, 'water' = Kalt- und Warmwasser, sonst ein Medium. */
    #[Url(as: 'art')]
    public string $type = '';

    public ?int $editingId = null;

    public string $number = '';
    public string $medium = 'electricity';
    public string $location = '';
    public string $calibration_year = '';
    public string $factor = '1';
    public bool $is_main = false;
    public string $parent_id = '';
    public string $tenant_id = '';
    public bool $is_active = true;
    public string $initial_value = '';
    public string $starts_on = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedType(): void
    {
        $this->resetPage();
    }

    public function updatedMedium(): void
    {
        // Hauptzähler gehören immer zum gleichen Medium (Strom bzw. Wasser).
        $this->parent_id = '';
    }

    public function mediumEnum(): Medium
    {
        return Medium::tryFrom($this->medium) ?? Medium::Electricity;
    }

    #[Computed]
    public function meters()
    {
        return Meter::query()
            ->with(['tenant', 'parent'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$this->search}%")
                ->orWhere('location', 'like', "%{$this->search}%")
                ->orWhereHas('tenant', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($this->status === 'main', fn ($q) => $q->where('is_main', true))
            ->ofType($this->type)
            ->orderByDesc('is_main')
            ->orderBy('number')
            ->paginate(25);
    }

    #[Computed]
    public function tenants()
    {
        return Tenant::query()->active()->orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function mainMeters()
    {
        return Meter::query()->main()->active()
            ->when($this->mediumEnum()->isWater(), fn ($q) => $q->water(), fn ($q) => $q->electricity())
            ->orderBy('number')
            ->get(['id', 'number', 'location', 'medium']);
    }

    /** Beim Bearbeiten nur Wechsel innerhalb von Strom bzw. Wasser – die gespeicherten Stände (kWh/Liter) bleiben gültig. */
    #[Computed]
    public function mediumOptions(): array
    {
        if (! $this->editingId) {
            return Medium::cases();
        }

        return Meter::findOrFail($this->editingId)->isWater() ? Medium::water() : [Medium::Electricity];
    }

    public function create(): void
    {
        Gate::authorize('manage');
        $this->resetForm();
        $this->starts_on = now()->startOfMonth()->toDateString();
        Flux::modal('meter-form')->show();
    }

    public function edit(Meter $meter): void
    {
        Gate::authorize('manage');
        $this->resetForm();
        $this->editingId = $meter->id;
        $this->fill([
            'number' => $meter->number,
            'medium' => $meter->medium->value,
            'location' => (string) $meter->location,
            'calibration_year' => (string) $meter->calibration_year,
            'factor' => (string) $meter->factor,
            'is_main' => $meter->is_main,
            'parent_id' => (string) $meter->parent_id,
            'tenant_id' => (string) $meter->tenant_id,
            'is_active' => $meter->is_active,
        ]);
        Flux::modal('meter-form')->show();
    }

    public function save(MeterService $service): void
    {
        Gate::authorize('manage');

        $data = $this->validate([
            'number' => ['required', 'string', 'max:255'],
            'medium' => ['required', Rule::in(array_map(fn (Medium $m) => $m->value, $this->mediumOptions))],
            'location' => ['nullable', 'string', 'max:255'],
            'calibration_year' => ['nullable', 'integer', 'min:1980', 'max:'.(now()->year + 1)],
            'factor' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_main' => ['boolean'],
            'parent_id' => ['nullable', Rule::notIn([$this->editingId]), Rule::exists('meters', 'id')->where('is_main', true)
                ->whereIn('medium', $this->mediumEnum()->isWater() ? array_map(fn (Medium $m) => $m->value, Medium::water()) : [Medium::Electricity->value])],
            'tenant_id' => ['nullable', 'exists:tenants,id'],
            'is_active' => ['boolean'],
            'initial_value' => [$this->editingId ? 'nullable' : 'required', 'nullable', new MeterValue($this->mediumEnum())],
            'starts_on' => [$this->editingId ? 'nullable' : 'required', 'nullable', 'date'],
        ]);

        $attributes = collect($data)->only(['number', 'medium', 'location', 'calibration_year', 'factor', 'is_main', 'parent_id', 'is_active'])
            ->map(fn ($v) => $v === '' ? null : $v)
            ->all();

        if ($this->mediumEnum()->isWater()) {
            // Wandlerfaktor gibt es nur bei Stromzählern.
            $attributes['factor'] = 1;
        }

        if ($this->is_main) {
            $attributes['parent_id'] = null;
        }

        if ($this->editingId) {
            // Mieter werden nur über "Mieterwechsel" geändert, damit die Historie stimmt.
            Meter::findOrFail($this->editingId)->update($attributes);
        } else {
            $service->create(
                [...$attributes, 'tenant_id' => $this->tenant_id ?: null, 'installed_on' => $this->starts_on],
                $this->mediumEnum()->parse($this->initial_value),
                CarbonImmutable::parse($this->starts_on),
                Auth::user(),
            );
        }

        Flux::modal('meter-form')->close();
        Flux::toast(__('Zähler gespeichert.'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'number', 'medium', 'location', 'calibration_year', 'is_main', 'parent_id', 'tenant_id', 'initial_value', 'starts_on']);
        $this->factor = '1';
        $this->is_active = true;
        $this->resetValidation();
    }
}; ?>

<div>
    <x-page-header :title="__('Zähler')" :subtitle="__('Strom- und Wasserzähler, Hauptzähler und Zuordnung zu Mietern')">
        @can('manage')
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Zähler hinzufügen') }}</flux:button>
        @endcan
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Zählernummer, Ort oder Mieter')" class="max-w-sm" />
        <flux:select wire:model.live="status" class="max-w-48">
            <flux:select.option value="active">{{ __('Aktive') }}</flux:select.option>
            <flux:select.option value="main">{{ __('Hauptzähler') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inaktive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('Alle') }}</flux:select.option>
        </flux:select>
        <flux:select wire:model.live="type" class="max-w-56">
            <flux:select.option value="">{{ __('Alle Zählerarten') }}</flux:select.option>
            <flux:select.option value="electricity">{{ __('Strom') }}</flux:select.option>
            <flux:select.option value="water">{{ __('Wasser (kalt und warm)') }}</flux:select.option>
            <flux:select.option value="cold_water">{{ __('Kaltwasser') }}</flux:select.option>
            <flux:select.option value="hot_water">{{ __('Warmwasser') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->meters">
        <flux:table.columns>
            <flux:table.column>{{ __('Zählernummer') }}</flux:table.column>
            <flux:table.column>{{ __('Art') }}</flux:table.column>
            <flux:table.column>{{ __('Ort / Gebäude') }}</flux:table.column>
            <flux:table.column>{{ __('Mieter') }}</flux:table.column>
            <flux:table.column>{{ __('Hauptzähler') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Faktor') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Aktiv') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->meters as $meter)
                <flux:table.row :key="$meter->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('meters.show', $meter)" wire:navigate>{{ $meter->number }}</flux:link>
                        @if ($meter->is_main) <flux:badge size="sm" color="amber" class="ms-1">{{ __('Hauptzähler') }}</flux:badge> @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$meter->medium->color()">{{ $meter->medium->label() }}</flux:badge>
                        @if ($meter->calibrationValidUntil())
                            <div @class(['mt-1 text-xs', 'text-red-600 dark:text-red-400' => $meter->calibrationExpired(), 'text-zinc-500' => ! $meter->calibrationExpired()])>
                                {{ __('Eichung bis :year', ['year' => $meter->calibrationValidUntil()]) }}
                            </div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>{{ $meter->location }}</flux:table.cell>
                    <flux:table.cell>{{ $meter->tenant?->name ?? '–' }}</flux:table.cell>
                    <flux:table.cell>{{ $meter->parent?->number ?? '–' }}</flux:table.cell>
                    <flux:table.cell align="center">{{ $meter->isWater() ? '–' : $meter->factor }}</flux:table.cell>
                    <flux:table.cell align="center">
                        <flux:badge size="sm" :color="$meter->is_active ? 'green' : 'zinc'">{{ $meter->is_active ? __('Ja') : __('Nein') }}</flux:badge>
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        <flux:button size="sm" variant="ghost" icon="eye" :href="route('meters.show', $meter)" wire:navigate />
                        @can('manage')
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $meter->id }})" />
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center text-zinc-500">{{ __('Keine Zähler gefunden.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="meter-form" class="md:w-xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Zähler bearbeiten') : __('Zähler hinzufügen') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><flux:input wire:model="number" :label="__('Zählernummer')" required /></div>
                <flux:select wire:model.live="medium" :label="__('Art')">
                    @foreach ($this->mediumOptions as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="sm:col-span-2"><flux:input wire:model="location" :label="__('Ort / Gebäudenummer')" /></div>
                @if ($this->mediumEnum()->isWater())
                    <flux:input wire:model="calibration_year" :label="__('Eichjahr')" type="number" min="1980" :max="now()->year + 1" :placeholder="now()->year" />
                @else
                    <flux:input wire:model="factor" :label="__('Zählerfaktor')" type="number" min="1" required />
                @endif
            </div>
            @if ($this->mediumEnum()->isWater())
                <flux:switch wire:model.live="is_main" :label="__('Hauptzähler')" :description="__('Hauptwasserzähler des Versorgers – Unterzähler können ihm zugeordnet werden.')" />
            @else
                <flux:switch wire:model.live="is_main" :label="__('Hauptzähler')" :description="__('Für Hauptzähler werden die Strompreise aus der Versorgerrechnung erfasst.')" />
            @endif

            @unless ($is_main)
                <flux:select wire:model="parent_id" :label="__('Zugehöriger Hauptzähler')">
                    <flux:select.option value="">–</flux:select.option>
                    @foreach ($this->mainMeters as $main)
                        <flux:select.option :value="$main->id">{{ $main->number }} {{ $main->location ? '('.$main->location.')' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endunless

            @if ($editingId)
                <flux:callout icon="information-circle" variant="secondary" :text="__('Mieter und Zählerstand werden auf der Detailseite über „Mieterwechsel“ bzw. „Zählerwechsel“ geändert.')" />
                <flux:switch wire:model="is_active" :label="__('Aktiv')" />
            @else
                <flux:select wire:model="tenant_id" :label="__('Mieter')">
                    <flux:select.option value="">–</flux:select.option>
                    @foreach ($this->tenants as $tenant)
                        <flux:select.option :value="$tenant->id">{{ $tenant->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <div class="grid gap-4 sm:grid-cols-2">
                    @if ($this->mediumEnum()->isWater())
                        <flux:input wire:model="initial_value" :label="__('Anfangsstand (m³)')" inputmode="decimal" placeholder="123,456" required />
                    @else
                        <flux:input wire:model="initial_value" :label="__('Anfangsstand (kWh)')" type="number" min="0" required />
                    @endif
                    <flux:input wire:model="starts_on" :label="__('Stichtag')" :description="__('Meist der 1. des Monats')" type="date" required />
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

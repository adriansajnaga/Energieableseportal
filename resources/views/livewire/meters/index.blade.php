<?php

use App\Models\Meter;
use App\Models\Tenant;
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

    public ?int $editingId = null;

    public string $number = '';
    public string $location = '';
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
        return Meter::query()->main()->active()->orderBy('number')->get(['id', 'number', 'location']);
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
            'location' => (string) $meter->location,
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
            'location' => ['nullable', 'string', 'max:255'],
            'factor' => ['required', 'integer', 'min:1', 'max:10000'],
            'is_main' => ['boolean'],
            'parent_id' => ['nullable', Rule::exists('meters', 'id')->where('is_main', true), Rule::notIn([$this->editingId])],
            'tenant_id' => ['nullable', 'exists:tenants,id'],
            'is_active' => ['boolean'],
            'initial_value' => [$this->editingId ? 'nullable' : 'required', 'nullable', 'integer', 'min:0'],
            'starts_on' => [$this->editingId ? 'nullable' : 'required', 'nullable', 'date'],
        ]);

        $attributes = collect($data)->only(['number', 'location', 'factor', 'is_main', 'parent_id', 'is_active'])
            ->map(fn ($v) => $v === '' ? null : $v)
            ->all();

        if ($this->is_main) {
            $attributes['parent_id'] = null;
        }

        if ($this->editingId) {
            // Mieter werden nur über "Mieterwechsel" geändert, damit die Historie stimmt.
            Meter::findOrFail($this->editingId)->update($attributes);
        } else {
            $service->create(
                [...$attributes, 'tenant_id' => $this->tenant_id ?: null, 'installed_on' => $this->starts_on],
                (int) $this->initial_value,
                CarbonImmutable::parse($this->starts_on),
                Auth::user(),
            );
        }

        Flux::modal('meter-form')->close();
        Flux::toast(__('Zähler gespeichert.'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'number', 'location', 'is_main', 'parent_id', 'tenant_id', 'initial_value', 'starts_on']);
        $this->factor = '1';
        $this->is_active = true;
        $this->resetValidation();
    }
}; ?>

<div>
    <x-page-header :title="__('Zähler')" :subtitle="__('Stromzähler, Hauptzähler und Zuordnung zu Mietern')">
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
    </div>

    <flux:table :paginate="$this->meters">
        <flux:table.columns>
            <flux:table.column>{{ __('Zählernummer') }}</flux:table.column>
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
                    <flux:table.cell>{{ $meter->location }}</flux:table.cell>
                    <flux:table.cell>{{ $meter->tenant?->name ?? '–' }}</flux:table.cell>
                    <flux:table.cell>{{ $meter->parent?->number ?? '–' }}</flux:table.cell>
                    <flux:table.cell align="center">{{ $meter->factor }}</flux:table.cell>
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
                    <flux:table.cell colspan="7" class="text-center text-zinc-500">{{ __('Keine Zähler gefunden.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="meter-form" class="md:w-xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Zähler bearbeiten') : __('Zähler hinzufügen') }}</flux:heading>

            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="number" :label="__('Zählernummer')" required class="sm:col-span-2" />
                <flux:input wire:model="factor" :label="__('Zählerfaktor')" type="number" min="1" required />
            </div>
            <flux:input wire:model="location" :label="__('Ort / Gebäudenummer')" />
            <flux:switch wire:model.live="is_main" :label="__('Hauptzähler')" :description="__('Für Hauptzähler werden die Strompreise aus der Versorgerrechnung erfasst.')" />

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
                    <flux:input wire:model="initial_value" :label="__('Anfangsstand (kWh)')" type="number" min="0" required />
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

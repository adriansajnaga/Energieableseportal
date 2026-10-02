<?php

use App\Models\Tenant;
use App\Services\TenantService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new #[Title('Mieter')] class extends Component {
    use WithPagination;

    #[Url(as: 'suche')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $status = 'active';

    public ?int $editingId = null;

    public string $name = '';
    public string $debtor_number = '';
    public string $street = '';
    public string $zip = '';
    public string $city = '';
    public string $phone = '';
    public string $email = '';
    public string $price_factor = '';
    public bool $send_invoices_by_email = false;
    public bool $issues_invoices = true;
    public bool $is_active = true;
    public string $active_from = '';
    public string $active_until = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function tenants()
    {
        return Tenant::query()
            ->withCount(['meters' => fn ($q) => $q->where('is_active', true)])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$this->search}%")
                ->orWhere('debtor_number', 'like', "%{$this->search}%")
                ->orWhere('email', 'like', "%{$this->search}%")))
            ->when($this->status === 'active', fn ($q) => $q->where('is_active', true))
            ->when($this->status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->orderBy('name')
            ->paginate(25);
    }

    public function create(): void
    {
        Gate::authorize('manage');
        $this->resetForm();
        $this->active_from = now()->startOfMonth()->toDateString();
        Flux::modal('tenant-form')->show();
    }

    public function edit(Tenant $tenant): void
    {
        Gate::authorize('manage');
        $this->resetForm();
        $this->editingId = $tenant->id;
        $this->fill([
            ...collect($tenant->only(['name', 'street', 'zip', 'city', 'phone', 'email']))->map(fn ($v) => (string) $v)->all(),
            'debtor_number' => (string) $tenant->debtor_number,
            'price_factor' => (string) $tenant->price_factor,
            'send_invoices_by_email' => $tenant->send_invoices_by_email,
            'issues_invoices' => $tenant->issues_invoices,
            'is_active' => $tenant->is_active,
            'active_from' => $tenant->active_from?->toDateString() ?? '',
            'active_until' => $tenant->active_until?->toDateString() ?? '',
        ]);
        Flux::modal('tenant-form')->show();
    }

    public function save(TenantService $service): void
    {
        Gate::authorize('manage');

        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'debtor_number' => ['nullable', 'integer', 'min:1', Rule::unique('tenants')->ignore($this->editingId)],
            'street' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:10'],
            'city' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', 'required_if:send_invoices_by_email,true'],
            'price_factor' => ['nullable', 'numeric', 'min:0.5', 'max:5'],
            'send_invoices_by_email' => ['boolean'],
            'issues_invoices' => ['boolean'],
            'is_active' => ['boolean'],
            'active_from' => ['nullable', 'date'],
            'active_until' => ['nullable', 'date', 'after_or_equal:active_from'],
        ]);

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        // Ohne Rechnungen gibt es auch keinen E-Mail-Versand von Rechnungen.
        if (! $data['issues_invoices']) {
            $data['send_invoices_by_email'] = false;
        }
        $until = $data['active_until'] ? CarbonImmutable::parse($data['active_until']) : null;
        unset($data['active_until']);

        // Deaktivieren ohne Enddatum beendet das Mietverhältnis heute (wie im Altsystem: Zähler werden frei).
        if (! $data['is_active'] && ! $until && $this->editingId && Tenant::find($this->editingId)?->assignments()->whereNull('ends_on')->exists()) {
            $until = CarbonImmutable::today();
        }

        $tenant = Tenant::updateOrCreate(['id' => $this->editingId], $data);

        if ($until?->toDateString() !== $tenant->active_until?->toDateString()) {
            try {
                $service->setActiveUntil($tenant, $until);
            } catch (RuntimeException $e) {
                $this->addError('active_until', $e->getMessage());

                return;
            }
        }

        Flux::modal('tenant-form')->close();
        Flux::toast(__('Mieter gespeichert.'), variant: 'success');
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'name', 'debtor_number', 'street', 'zip', 'city', 'phone', 'email', 'price_factor', 'send_invoices_by_email', 'active_from', 'active_until']);
        $this->issues_invoices = true;
        $this->is_active = true;
        $this->resetValidation();
    }
}; ?>

<div>
    <x-page-header :title="__('Mieter')" :subtitle="__('Stammdaten der Mieter und Rechnungsempfänger')">
        @can('view-finance')
            <flux:button icon="arrow-down-tray" :href="route('export.tenants')">{{ __('Export (CSV)') }}</flux:button>
        @endcan
        @can('manage')
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Mieter hinzufügen') }}</flux:button>
        @endcan
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Name, Kundennr. oder E-Mail')" class="max-w-sm" />
        <flux:select wire:model.live="status" class="max-w-48">
            <flux:select.option value="active">{{ __('Aktive') }}</flux:select.option>
            <flux:select.option value="inactive">{{ __('Inaktive') }}</flux:select.option>
            <flux:select.option value="all">{{ __('Alle') }}</flux:select.option>
        </flux:select>
    </div>

    <flux:table :paginate="$this->tenants">
        <flux:table.columns>
            <flux:table.column>{{ __('Name') }}</flux:table.column>
            <flux:table.column>{{ __('Kundennr.') }}</flux:table.column>
            <flux:table.column>{{ __('Adresse') }}</flux:table.column>
            <flux:table.column>{{ __('Kontakt') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Zähler') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Preisfaktor') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Aktiv') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->tenants as $tenant)
                <flux:table.row :key="$tenant->id">
                    <flux:table.cell variant="strong">{{ $tenant->name }}</flux:table.cell>
                    <flux:table.cell>{{ $tenant->debtor_number }}</flux:table.cell>
                    <flux:table.cell>{{ $tenant->street }}<br><span class="text-xs text-zinc-500">{{ $tenant->zip }} {{ $tenant->city }}</span></flux:table.cell>
                    <flux:table.cell>
                        {{ $tenant->phone }}
                        @if ($tenant->email)
                            <br><span class="text-xs text-zinc-500">{{ $tenant->email }}</span>
                            @if ($tenant->send_invoices_by_email) <flux:icon.envelope variant="micro" class="inline text-emerald-600" /> @endif
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="center">{{ $tenant->meters_count }}</flux:table.cell>
                    <flux:table.cell align="center">
                        {{ $tenant->price_factor ? (float) $tenant->price_factor : __('Standard') }}
                        @unless ($tenant->issues_invoices)
                            <div class="mt-1"><flux:badge size="sm" color="amber">{{ __('Pauschale') }}</flux:badge></div>
                        @endunless
                    </flux:table.cell>
                    <flux:table.cell align="center">
                        <flux:badge size="sm" :color="$tenant->is_active ? 'green' : 'zinc'">{{ $tenant->is_active ? __('Ja') : __('Nein') }}</flux:badge>
                        @if ($tenant->active_until)
                            <div class="mt-1 text-xs text-zinc-500">{{ __('bis :date', ['date' => $tenant->active_until->format('d.m.Y')]) }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @can('manage')
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $tenant->id }})" />
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center text-zinc-500">{{ __('Keine Mieter gefunden.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="tenant-form" class="md:w-xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Mieter bearbeiten') : __('Mieter hinzufügen') }}</flux:heading>

            <flux:input wire:model="name" :label="__('Name')" required />
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="debtor_number" :label="__('Kundennr. (Debitor)')" type="number" />
                <flux:input wire:model="active_from" :label="__('Mieter seit')" type="date" />
                <flux:input wire:model="active_until" :label="__('Mieter bis')" type="date" :description="__('Letzter Tag. Danach erscheint der Mieter nicht mehr in der Abrechnung.')" />
            </div>
            <flux:input wire:model="street" :label="__('Straße')" />
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="zip" :label="__('PLZ')" />
                <flux:input wire:model="city" :label="__('Ort')" class="sm:col-span-2" />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="phone" :label="__('Telefon')" />
                <flux:input wire:model="email" :label="__('E-Mail')" type="email" />
            </div>
            <flux:input wire:model="price_factor" :label="__('Individueller Preisfaktor')" :description="__('Leer lassen für den Standardwert aus den Einstellungen.')" type="number" step="0.001" />
            <flux:switch wire:model.live="issues_invoices" :label="__('Rechnungen ausstellen')" :description="__('Aus = Pauschalmieter: Die Abrechnung dient nur der Kontrolle, es wird nie eine Rechnung mit Nummer erstellt.')" />
            @if ($issues_invoices)
                <flux:switch wire:model="send_invoices_by_email" :label="__('Rechnungen per E-Mail senden')" />
            @endif
            <flux:switch wire:model="is_active" :label="__('Aktiv')" :description="__('Beim Deaktivieren werden die Zähler vom Mieter gelöst.')" />

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

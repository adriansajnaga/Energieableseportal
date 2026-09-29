<?php

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Settlement;
use App\Services\ReadingService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Title('Zählerstände')] class extends Component {
    use WithFileUploads, WithPagination;

    #[Url(as: 'suche')]
    public string $search = '';

    #[Url]
    public string $status = '';

    #[Url(as: 'zaehler')]
    public string $meterFilter = '';

    #[Url(as: 'monat')]
    public string $monthFilter = '';

    public bool $hideSystem = true;

    public ?int $editingId = null;

    public string $meter_id = '';
    public string $value = '';
    public string $read_on = '';
    public bool $is_base = false;
    public $photo = null;

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'meterFilter', 'monthFilter', 'hideSystem'])) {
            $this->resetPage();
        }
    }

    #[Computed]
    public function readings()
    {
        return Reading::query()
            ->with(['meter.tenant', 'tenant'])
            ->when($this->search, fn ($q) => $q->where(fn ($q) => $q
                ->whereHas('meter', fn ($q) => $q->where('number', 'like', "%{$this->search}%"))
                ->orWhereHas('tenant', fn ($q) => $q->where('name', 'like', "%{$this->search}%"))
                ->orWhere('reader_name', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->meterFilter, fn ($q) => $q->where('meter_id', $this->meterFilter))
            ->when($this->monthFilter, function ($q) {
                $month = CarbonImmutable::createFromFormat('!Y-m', $this->monthFilter);
                $q->whereBetween('read_on', [$month->toDateString(), $month->endOfMonth()->toDateString()]);
            })
            ->when($this->hideSystem, fn ($q) => $q->where('source', '!=', ReadingSource::System))
            ->orderByDesc('read_on')
            ->orderByDesc('id')
            ->paginate(30);
    }

    #[Computed]
    public function meters()
    {
        return Meter::query()->active()->with('tenant')->orderBy('number')->get();
    }

    #[On('record-reading')]
    public function create(?int $meterId = null): void
    {
        Gate::authorize('record-readings');
        $this->resetForm();
        $this->meter_id = (string) $meterId;
        $this->read_on = now()->toDateString();
        Flux::modal('reading-form')->show();
    }

    public function edit(Reading $reading): void
    {
        Gate::authorize('manage');
        abort_if($this->isUsedInSettlement($reading), 403);

        $this->resetForm();
        $this->editingId = $reading->id;
        $this->meter_id = (string) $reading->meter_id;
        $this->value = (string) $reading->value;
        $this->read_on = $reading->read_on->toDateString();
        $this->is_base = $reading->is_base;
        Flux::modal('reading-form')->show();
    }

    public function save(ReadingService $service): void
    {
        Gate::authorize('record-readings');

        $this->validate([
            'meter_id' => ['required', 'exists:meters,id'],
            'value' => ['required', 'integer', 'min:0'],
            'read_on' => ['required', 'date', 'before_or_equal:today'],
            'photo' => ['nullable', 'image', 'max:10240'],
            'is_base' => ['boolean'],
        ]);

        $user = Auth::user();
        $date = CarbonImmutable::parse($this->read_on);

        if ($this->editingId) {
            Gate::authorize('manage');
            $reading = $service->update(Reading::findOrFail($this->editingId), (int) $this->value, $date, $this->is_base, $this->photo, $user);
        } else {
            $reading = $service->record(
                Meter::findOrFail($this->meter_id),
                (int) $this->value,
                $date,
                $user->isAdmin() ? ReadingSource::Admin : ReadingSource::Caretaker,
                photo: $this->photo,
                user: $user,
                isBase: $user->isAdmin() && $this->is_base,
            );
        }

        Flux::modal('reading-form')->close();

        $reading->status === ReadingStatus::Pending
            ? Flux::toast($reading->check_note, heading: __('Ablesung zur Prüfung markiert'), variant: 'warning')
            : Flux::toast(__('Ablesung gespeichert.'), variant: 'success');

        $this->dispatch('reading-saved');
    }

    public function approve(Reading $reading, ReadingService $service): void
    {
        Gate::authorize('manage');
        $service->approve($reading, Auth::user());
        Flux::toast(__('Ablesung freigegeben.'), variant: 'success');
    }

    public function reject(Reading $reading, ReadingService $service): void
    {
        Gate::authorize('manage');
        $service->reject($reading, Auth::user());
        Flux::toast(__('Ablesung abgelehnt.'));
    }

    public function delete(Reading $reading): void
    {
        Gate::authorize('manage');

        if ($this->isUsedInSettlement($reading)) {
            Flux::toast(__('Die Ablesung wird in einer Abrechnung verwendet und kann nicht gelöscht werden.'), variant: 'danger');

            return;
        }

        $reading->deletePhoto();
        $reading->delete();
        Flux::toast(__('Ablesung gelöscht.'));
    }

    public function isUsedInSettlement(Reading $reading): bool
    {
        return Settlement::query()->whereNull('cancelled_at')
            ->where(fn ($q) => $q->where('start_reading_id', $reading->id)
                ->orWhere('end_reading_id', $reading->id)
                ->orWhere('sample_reading_id', $reading->id))
            ->exists();
    }

    private function resetForm(): void
    {
        $this->reset(['editingId', 'meter_id', 'value', 'read_on', 'is_base', 'photo']);
        $this->resetValidation();
    }
}; ?>

<div>
    <x-page-header :title="__('Zählerstände')" :subtitle="__('Alle Ablesungen mit Foto und Plausibilitätsprüfung')">
        @can('record-readings')
            <flux:button variant="primary" icon="plus" wire:click="create">{{ __('Zähler ablesen') }}</flux:button>
        @endcan
    </x-page-header>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Zähler, Mieter oder Ableser')" class="max-w-xs" />
        <flux:select wire:model.live="status" class="max-w-44">
            <flux:select.option value="">{{ __('Alle Status') }}</flux:select.option>
            @foreach (ReadingStatus::cases() as $case)
                <flux:select.option :value="$case->value">{{ $case->label() }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:input wire:model.live="monthFilter" type="month" class="max-w-44" />
        <flux:checkbox wire:model.live="hideSystem" :label="__('Berechnete Stände ausblenden')" />
    </div>

    <flux:table :paginate="$this->readings">
        <flux:table.columns>
            <flux:table.column>{{ __('Datum') }}</flux:table.column>
            <flux:table.column>{{ __('Zähler') }}</flux:table.column>
            <flux:table.column>{{ __('Mieter') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Stand (kWh)') }}</flux:table.column>
            <flux:table.column>{{ __('Ableser') }}</flux:table.column>
            <flux:table.column align="center">{{ __('Foto') }}</flux:table.column>
            <flux:table.column>{{ __('Status') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->readings as $reading)
                <flux:table.row :key="$reading->id">
                    <flux:table.cell>
                        {{ $reading->read_on->format('d.m.Y') }}
                        @if ($reading->is_base) <flux:badge size="sm" color="sky">{{ __('Basis') }}</flux:badge> @endif
                    </flux:table.cell>
                    <flux:table.cell variant="strong"><flux:link :href="route('meters.show', $reading->meter_id)" wire:navigate>{{ $reading->meter->number }}</flux:link></flux:table.cell>
                    <flux:table.cell>{{ $reading->tenant?->name ?? '–' }}</flux:table.cell>
                    <flux:table.cell align="end">{{ number_format($reading->value, 0, ',', '.') }}</flux:table.cell>
                    <flux:table.cell>
                        {{ $reading->reader_name }}
                        <br><span class="text-xs text-zinc-500">{{ $reading->source->label() }}</span>
                    </flux:table.cell>
                    <flux:table.cell align="center">
                        @if ($reading->photo_path)
                            <flux:button size="sm" variant="ghost" icon="photo" :href="$reading->photoUrl()" target="_blank" />
                        @endif
                    </flux:table.cell>
                    <flux:table.cell>
                        <flux:badge size="sm" :color="$reading->status->color()">{{ $reading->status->label() }}</flux:badge>
                        @if ($reading->check_note)
                            <div class="mt-1 max-w-64 whitespace-normal text-xs text-amber-600">{{ $reading->check_note }}</div>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">
                        @can('manage')
                            @if ($reading->status !== ReadingStatus::Approved)
                                <flux:button size="sm" variant="ghost" icon="check" wire:click="approve({{ $reading->id }})" :tooltip="__('Freigeben')" />
                            @endif
                            @if ($reading->status === ReadingStatus::Pending)
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="reject({{ $reading->id }})" :tooltip="__('Ablehnen')" />
                            @endif
                            @unless ($this->isUsedInSettlement($reading))
                                <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $reading->id }})" />
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $reading->id }})" wire:confirm="{{ __('Ablesung wirklich löschen?') }}" />
                            @endunless
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="8" class="text-center text-zinc-500">{{ __('Keine Ablesungen gefunden.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="reading-form" class="md:w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? __('Ablesung bearbeiten') : __('Zähler ablesen') }}</flux:heading>

            <flux:select wire:model="meter_id" :label="__('Zähler')" :disabled="(bool) $editingId" required>
                <flux:select.option value="">–</flux:select.option>
                @foreach ($this->meters as $meter)
                    <flux:select.option :value="$meter->id">{{ $meter->number }} – {{ $meter->tenant?->name ?? $meter->location }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="value" :label="__('Zählerstand (kWh, ohne Nachkommastellen)')" type="number" min="0" required />
                <flux:input wire:model="read_on" :label="__('Ablesedatum')" type="date" required />
            </div>
            <flux:input wire:model="photo" :label="__('Foto vom Zählerstand')" type="file" accept="image/*" capture="environment" />
            @can('manage')
                <flux:checkbox wire:model="is_base" :label="__('Als Anfangsstand (Abrechnungsgrundlage) verwenden')" />
            @endcan

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

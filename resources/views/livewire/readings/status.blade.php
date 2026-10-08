<?php

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Models\Meter;
use App\Models\Setting;
use App\Rules\MeterValue;
use App\Services\ReadingService;
use App\Services\Statistics;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Title('Ablesestatus')] class extends Component {
    use WithFileUploads;

    /** '' = alle, 'electricity' = Strom, 'water' = Wasser. */
    #[Url(as: 'art')]
    public string $type = '';

    public ?int $meterId = null;
    public string $value = '';
    public $photo = null;

    #[Computed]
    public function missing()
    {
        return app(Statistics::class)->metersWithoutReading(now(), $this->type);
    }

    #[Computed]
    public function total(): int
    {
        return Meter::query()->active()->ofType($this->type)->count();
    }

    #[Computed]
    public function meter(): ?Meter
    {
        return $this->meterId ? Meter::find($this->meterId) : null;
    }

    public function open(int $meterId): void
    {
        Gate::authorize('record-readings');
        $this->reset(['value', 'photo']);
        $this->resetValidation();
        $this->meterId = $meterId;
        unset($this->meter);
        Flux::modal('quick-reading')->show();
    }

    public function save(ReadingService $service): void
    {
        Gate::authorize('record-readings');
        $meter = Meter::findOrFail($this->meterId);

        $this->validate([
            'value' => ['required', new MeterValue($meter->medium)],
            'photo' => ['nullable', 'image', 'max:10240'],
        ]);

        $reading = $service->record(
            $meter,
            $meter->parseValue($this->value),
            CarbonImmutable::today(),
            Auth::user()->isAdmin() ? ReadingSource::Admin : ReadingSource::Caretaker,
            photo: $this->photo,
            user: Auth::user(),
        );

        unset($this->missing);
        Flux::modal('quick-reading')->close();

        $reading->status === ReadingStatus::Pending
            ? Flux::toast($reading->check_note, heading: __('Ablesung zur Prüfung markiert'), variant: 'warning')
            : Flux::toast(__('Ablesung gespeichert.'), variant: 'success');
    }
}; ?>

<div>
    <x-page-header :title="__('Ablesestatus')" :subtitle="__('Diese Zähler müssen im :month noch abgelesen werden (am besten bis zum :day. des Monats).', ['month' => now()->locale(app()->getLocale())->isoFormat('MMMM'), 'day' => Setting::get('reading_deadline_day')])">
        <flux:badge size="lg" :color="$this->missing->isEmpty() ? 'green' : 'amber'">
            {{ $this->total - $this->missing->count() }} / {{ $this->total }} {{ __('abgelesen') }}
        </flux:badge>
    </x-page-header>

    <div class="mb-4 flex flex-wrap gap-3">
        <flux:select wire:model.live="type" class="w-full sm:max-w-56">
            <flux:select.option value="">{{ __('Alle Zählerarten') }}</flux:select.option>
            <flux:select.option value="electricity">{{ __('Strom') }}</flux:select.option>
            <flux:select.option value="water">{{ __('Wasser (kalt und warm)') }}</flux:select.option>
        </flux:select>
    </div>

    @if ($this->missing->isEmpty())
        <flux:callout icon="check-circle" color="green" :heading="__('Alle Zähler sind für diesen Monat abgelesen.')" />
    @else
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->missing as $i => $meter)
                <flux:card class="flex min-w-0 items-center justify-between gap-3 p-4!" wire:key="missing-{{ $meter->id }}">
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold break-words">
                            {{ $loop->iteration }}. {{ $meter->number }}
                            @if ($meter->isWater()) <flux:badge size="sm" :color="$meter->medium->color()" class="ms-1">{{ $meter->medium->label() }}</flux:badge> @endif
                        </div>
                        <div class="text-sm break-words text-zinc-500">{{ $meter->tenant?->name ?? __('Kein Mieter') }}@if ($meter->location) · {{ $meter->location }}@endif</div>
                        @if ($meter->tenant?->phone)
                            <a href="tel:{{ $meter->tenant->phone }}" class="mt-1 inline-block text-sm text-emerald-600">{{ $meter->tenant->phone }}</a>
                        @endif
                    </div>
                    @can('record-readings')
                        <flux:button size="sm" variant="primary" class="shrink-0" wire:click="open({{ $meter->id }})">{{ __('Ablesen') }}</flux:button>
                    @endcan
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:modal name="quick-reading" class="w-full md:w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ __('Zähler ablesen') }}</flux:heading>
            @if ($this->meter)
                <flux:text>
                    {{ $this->meter->label() }}
                    @if ($this->meter->isWater()) · {{ $this->meter->medium->label() }} @endif
                    · {{ __('Letzter Stand') }}: {{ $this->meter->formatValue($this->meter->latestReading()?->value ?? 0, true) }}
                </flux:text>
                <x-meter-value-input wire:model="value" :medium="$this->meter->medium" :label="__('Zählerstand')" autofocus
                    :description="$this->meter->isWater() ? __('Mit allen Nachkommastellen') : __('Ohne Nachkommastellen')" />
            @endif
            <flux:input wire:model="photo" :label="__('Foto vom Zählerstand')" type="file" accept="image/*" capture="environment" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

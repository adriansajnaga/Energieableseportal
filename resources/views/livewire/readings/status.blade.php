<?php

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Models\Meter;
use App\Models\Setting;
use App\Services\ReadingService;
use App\Services\Statistics;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Title('Ablesestatus')] class extends Component {
    use WithFileUploads;

    public ?int $meterId = null;
    public string $value = '';
    public $photo = null;

    #[Computed]
    public function missing()
    {
        return app(Statistics::class)->metersWithoutReading(now());
    }

    #[Computed]
    public function total(): int
    {
        return Meter::query()->active()->count();
    }

    public function open(int $meterId): void
    {
        Gate::authorize('record-readings');
        $this->reset(['value', 'photo']);
        $this->resetValidation();
        $this->meterId = $meterId;
        Flux::modal('quick-reading')->show();
    }

    public function save(ReadingService $service): void
    {
        Gate::authorize('record-readings');
        $this->validate([
            'value' => ['required', 'integer', 'min:0'],
            'photo' => ['nullable', 'image', 'max:10240'],
        ]);

        $reading = $service->record(
            Meter::findOrFail($this->meterId),
            (int) $this->value,
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

    @if ($this->missing->isEmpty())
        <flux:callout icon="check-circle" color="green" :heading="__('Alle Zähler sind für diesen Monat abgelesen.')" />
    @else
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($this->missing as $i => $meter)
                <flux:card class="flex items-center justify-between gap-3" wire:key="missing-{{ $meter->id }}">
                    <div class="min-w-0">
                        <div class="font-semibold">{{ $i + 1 }}. {{ $meter->number }}</div>
                        <div class="truncate text-sm text-zinc-500">{{ $meter->tenant?->name ?? __('Kein Mieter') }} · {{ $meter->location }}</div>
                        @if ($meter->tenant?->phone)
                            <a href="tel:{{ $meter->tenant->phone }}" class="text-sm text-emerald-600">{{ $meter->tenant->phone }}</a>
                        @endif
                    </div>
                    @can('record-readings')
                        <flux:button size="sm" variant="primary" wire:click="open({{ $meter->id }})">{{ __('Ablesen') }}</flux:button>
                    @endcan
                </flux:card>
            @endforeach
        </div>
    @endif

    <flux:modal name="quick-reading" class="w-full md:w-md">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ __('Zähler ablesen') }}</flux:heading>
            @if ($meterId)
                <flux:text>{{ \App\Models\Meter::find($meterId)?->label() }} · {{ __('Letzter Stand') }}: {{ number_format(\App\Models\Meter::find($meterId)?->latestReading()?->value ?? 0, 0, ',', '.') }} kWh</flux:text>
            @endif
            <flux:input wire:model="value" :label="__('Zählerstand (kWh, ohne Nachkommastellen)')" type="number" inputmode="numeric" min="0" required autofocus />
            <flux:input wire:model="photo" :label="__('Foto vom Zählerstand')" type="file" accept="image/*" capture="environment" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

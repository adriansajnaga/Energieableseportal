<?php

use App\Enums\ReadingSource;
use App\Models\Meter;
use App\Models\Setting;
use App\Services\ReadingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.auth')] #[Title('Zählerstand')] class extends Component {
    use WithFileUploads;

    #[Locked]
    public int $meterId;

    public string $value = '';
    public string $reader_name = '';
    public $photo = null;
    public bool $saved = false;

    public function mount(string $token): void
    {
        $meter = Meter::query()->where('qr_token', $token)->where('is_active', true)->firstOrFail();
        $this->meterId = $meter->id;
        $this->reader_name = Auth::user()?->name ?? '';
    }

    public function meter(): Meter
    {
        return Meter::findOrFail($this->meterId);
    }

    public function save(ReadingService $service): void
    {
        $isStaff = Auth::check() && Gate::allows('record-readings');

        $this->validate([
            'value' => ['required', 'integer', 'min:0', 'max:999999999'],
            'reader_name' => ['required', 'string', 'max:100'],
            'photo' => [$isStaff ? 'nullable' : 'required', 'nullable', 'image', 'max:10240'],
        ]);

        $service->record(
            $this->meter(),
            (int) $this->value,
            CarbonImmutable::today(),
            $isStaff ? ReadingSource::Caretaker : ReadingSource::TenantQr,
            $this->reader_name,
            $this->photo,
            Auth::user(),
        );

        $this->saved = true;
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header :title="__('Zählerstand melden')" :description="__('Zähler :number', ['number' => $this->meter()->number])" />

    @if ($saved)
        <flux:callout icon="check-circle" color="green" :heading="__('Vielen Dank!')" :text="__('Ihr Zählerstand wurde gespeichert.')" />
    @else
        @php($last = $this->meter()->latestReading())
        <div class="rounded-lg border border-zinc-200 p-4 text-sm dark:border-zinc-700">
            <div class="flex justify-between"><span class="text-zinc-500">{{ __('Ort') }}</span><span>{{ $this->meter()->location ?? '–' }}</span></div>
            <div class="flex justify-between"><span class="text-zinc-500">{{ __('Letzte Ablesung') }}</span><span>{{ $last?->read_on->format('d.m.Y') ?? '–' }}</span></div>
            <div class="flex justify-between"><span class="text-zinc-500">{{ __('Letzter Zählerstand') }}</span><span>{{ $last ? number_format($last->value, 0, ',', '.').' kWh' : '–' }}</span></div>
        </div>

        <form wire:submit="save" class="flex flex-col gap-5">
            <flux:input wire:model="value" :label="__('1. Zählerstand (kWh, ohne Nachkommastellen)')" type="number" inputmode="numeric" :min="$last?->value ?? 0" required autofocus />
            <flux:input wire:model="reader_name" :label="__('2. Ihr Name')" required autocomplete="name" />
            <flux:input wire:model="photo" :label="__('3. Foto vom Zählerstand')" type="file" accept="image/*" capture="environment" />
            <div wire:loading wire:target="photo" class="text-sm text-zinc-500">{{ __('Foto wird hochgeladen …') }}</div>
            <flux:text class="text-xs">{{ __('Ablesedatum: :date. Bitte bis zum :day. des Monats übermitteln.', ['date' => now()->format('d.m.Y'), 'day' => Setting::get('reading_deadline_day')]) }}</flux:text>
            <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled">{{ __('Zählerstand speichern') }}</flux:button>
        </form>
    @endif
</div>

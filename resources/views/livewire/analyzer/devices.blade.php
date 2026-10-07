<?php

use App\Models\AnalyzerDevice;
use App\Models\AnalyzerReading;
use App\Models\AnalyzerSlot;
use App\Models\Meter;
use App\Services\Analyzer\MeterReadingSync;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Analysator-Geräte')] class extends Component {
    public string $name = '';

    /** Token nach Anlage/Erneuerung, wird nur einmal angezeigt. */
    public ?string $shownToken = null;
    public ?string $shownDevice = null;

    // Zuordnung Platz -> Messpunkt
    public ?int $slotId = null;
    public string $meter_id = '';
    public string $since = '';

    #[Computed]
    public function devices()
    {
        return AnalyzerDevice::query()->withCount('readings')->with('slots.meter')->orderBy('name')->get();
    }

    #[Computed]
    public function recent()
    {
        return AnalyzerReading::query()->with('device')->orderByDesc('id')->limit(30)->get();
    }

    #[Computed]
    public function meters()
    {
        return Meter::query()->electricity()->active()->where('is_main', false)
            ->orderByDesc('is_analyzer')->orderBy('number')->get(['id', 'number', 'location', 'is_analyzer']);
    }

    public function timezone(): string
    {
        return config('energie.analyzer_timezone');
    }

    public function createDevice(): void
    {
        Gate::authorize('manage');
        $this->reset(['name']);
        $this->resetValidation();
        Flux::modal('device-form')->show();
    }

    public function saveDevice(): void
    {
        Gate::authorize('manage');
        $this->validate(['name' => ['required', 'string', 'max:31', 'unique:analyzer_devices,name']]);

        [$device, $token] = AnalyzerDevice::register(trim($this->name));

        Flux::modal('device-form')->close();
        $this->showToken($device, $token);
    }

    public function regenerateToken(int $deviceId): void
    {
        Gate::authorize('manage');
        $device = AnalyzerDevice::findOrFail($deviceId);
        $this->showToken($device, $device->regenerateToken());
    }

    /** Versehentlich angelegtes Gerät mit seinen Rohdaten löschen; übernommene Zählerstände bleiben erhalten. */
    public function deleteDevice(int $deviceId): void
    {
        Gate::authorize('manage');
        $device = AnalyzerDevice::findOrFail($deviceId);

        DB::transaction(function () use ($device) {
            $device->readings()->delete();
            AnalyzerSlot::query()->where('device_id', $device->id)->delete();
            $device->delete();
        });

        unset($this->devices, $this->recent);
        Flux::toast(__('Analysator „:name“ gelöscht.', ['name' => $device->name]), variant: 'success');
    }

    public function editSlot(int $slotId): void
    {
        Gate::authorize('manage');
        $deviceSlot = AnalyzerSlot::findOrFail($slotId);
        $this->slotId = $deviceSlot->id;
        $this->meter_id = (string) $deviceSlot->meter_id;
        $this->since = ($deviceSlot->meter_since?->setTimezone($this->timezone()) ?? CarbonImmutable::now($this->timezone()))->toDateString();
        $this->resetValidation();
        Flux::modal('slot-form')->show();
    }

    public function saveSlot(MeterReadingSync $sync): void
    {
        Gate::authorize('manage');
        $this->validate([
            'meter_id' => ['nullable', Rule::exists('meters', 'id')->where('is_main', 0)],
            'since' => ['required', 'date'],
        ]);

        $deviceSlot = AnalyzerSlot::findOrFail($this->slotId);
        $since = CarbonImmutable::parse($this->since, $this->timezone())->startOfDay();

        $deviceSlot->update([
            'meter_id' => $this->meter_id ?: null,
            'meter_since' => $this->meter_id ? $since : null,
        ]);

        $count = $this->meter_id ? $sync->backfill($deviceSlot->fresh(['meter', 'device']), $since) : 0;

        unset($this->devices);
        Flux::modal('slot-form')->close();
        Flux::toast($this->meter_id
            ? __('Zuordnung gespeichert, :count Tageswerte übernommen.', ['count' => $count])
            : __('Zuordnung aufgehoben.'), variant: 'success');
    }

    private function showToken(AnalyzerDevice $device, string $token): void
    {
        $this->shownDevice = $device->name;
        $this->shownToken = $token;
        unset($this->devices);
        Flux::modal('device-token')->show();
    }
}; ?>

<div>
    <x-page-header :title="__('Analysator-Geräte')" :subtitle="__('Mobile Analysatoren (ESP32) mit bis zu 6 Zählern F&F LE-03M – Daten kommen automatisch über die API')">
        <flux:button icon="share" variant="ghost" :href="route('analyzer.schema')" wire:navigate>{{ __('Leitungsschema') }}</flux:button>
        @can('manage')
            <flux:button variant="primary" icon="plus" wire:click="createDevice">{{ __('Analysator hinzufügen') }}</flux:button>
        @endcan
    </x-page-header>

    @can('manage')
        <flux:callout icon="information-circle" class="mb-6">
            <flux:callout.text>
                {{ __('Im Analysator eintragen – URL:') }} <code class="break-all font-mono">{{ route('api.analyzer.readings') }}</code>.
                {{ __('Den Token erhalten Sie beim Anlegen des Geräts (oder mit „Neuer Token“). Ordnen Sie danach jeden Platz einem Messpunkt aus dem Leitungsschema zu.') }}
            </flux:callout.text>
        </flux:callout>
    @endcan

    <div class="space-y-4">
        @forelse ($this->devices as $device)
            @php($month = CarbonImmutable::now($this->timezone()))
            <flux:card wire:key="device-{{ $device->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <flux:heading size="lg">{{ $device->name }}</flux:heading>
                        <flux:text size="sm">
                            {{ __('Zuletzt gemeldet') }}: {{ $device->last_seen_at?->setTimezone($this->timezone())->format('d.m.Y H:i') ?? __('noch nie') }}
                            · {{ __('Firmware') }} {{ $device->fw ?? '–' }}
                            · {{ __('Start-Nr.') }} {{ $device->last_boot ?? '–' }}
                            · {{ __(':count Ablesungen', ['count' => number_format($device->readings_count, 0, ',', '.')]) }}
                        </flux:text>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" icon="arrow-down-tray" :href="route('analyzer.api.export', [$device, 'from' => $month->startOfMonth()->toDateString(), 'to' => $month->toDateString()])">{{ __('CSV (Monat)') }}</flux:button>
                        <flux:button size="sm" icon="chart-bar" :href="route('analyzer.api.consumption', [$device, 'group' => 'day'])" target="_blank">{{ __('Tagesverbrauch (JSON)') }}</flux:button>
                        @can('manage')
                            <flux:button size="sm" variant="ghost" icon="key" wire:click="regenerateToken({{ $device->id }})"
                                wire:confirm="{{ __('Neuen Token erzeugen? Der bisherige Token im Gerät funktioniert danach nicht mehr.') }}">{{ __('Neuer Token') }}</flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteDevice({{ $device->id }})"
                                wire:confirm="{{ __('Analysator „:name“ löschen? Seine :count empfangenen Rohdaten werden gelöscht, bereits übernommene Zählerstände der Messpunkte bleiben erhalten.', ['name' => $device->name, 'count' => $device->readings_count]) }}">{{ __('Löschen') }}</flux:button>
                        @endcan
                    </div>
                </div>

                <flux:table class="mt-3">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Platz') }}</flux:table.column>
                        <flux:table.column>{{ __('Name im Gerät') }}</flux:table.column>
                        <flux:table.column>{{ __('Adresse / Modell') }}</flux:table.column>
                        <flux:table.column align="end">{{ __('Letzter Stand') }}</flux:table.column>
                        <flux:table.column>{{ __('Messpunkt') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($device->slots as $deviceSlot)
                            @php($last = $deviceSlot->latestReading())
                            <flux:table.row :key="'slot-'.$deviceSlot->id">
                                <flux:table.cell variant="strong">{{ $deviceSlot->slot }}</flux:table.cell>
                                <flux:table.cell>{{ $deviceSlot->name ?? '–' }}</flux:table.cell>
                                <flux:table.cell>{{ $deviceSlot->addr ?? '–' }} · {{ $deviceSlot->model ?? '–' }}</flux:table.cell>
                                <flux:table.cell align="end" class="whitespace-nowrap">
                                    @if ($last)
                                        {{ number_format((float) $last->kwh, 2, ',', '.') }} kWh
                                        <div class="text-xs text-zinc-500">{{ $last->localTs()?->format('d.m.Y H:i') ?? __('ohne Zeit') }}</div>
                                    @else
                                        –
                                    @endif
                                </flux:table.cell>
                                <flux:table.cell>
                                    @if ($deviceSlot->meter)
                                        <flux:link :href="route('meters.show', $deviceSlot->meter)" wire:navigate>{{ $deviceSlot->meter->number }}</flux:link>
                                        <span class="text-xs text-zinc-500">{{ $deviceSlot->meter->location }} · {{ __('ab :date', ['date' => $deviceSlot->meter_since?->setTimezone($this->timezone())->format('d.m.Y')]) }}</span>
                                    @else
                                        <span class="text-zinc-500">{{ __('nicht zugeordnet') }}</span>
                                    @endif
                                    @can('manage')
                                        <flux:button size="xs" variant="ghost" icon="pencil-square" wire:click="editSlot({{ $deviceSlot->id }})" />
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('Noch keine Daten vom Gerät empfangen.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @empty
            <flux:callout icon="cpu-chip" :text="__('Noch kein Analysator angelegt.')" />
        @endforelse
    </div>

    @if ($this->recent->isNotEmpty())
        <flux:heading size="lg" class="mt-8">{{ __('Letzte empfangene Ablesungen') }}</flux:heading>
        <flux:table class="mt-2">
            <flux:table.columns>
                <flux:table.column>{{ __('Zeit') }}</flux:table.column>
                <flux:table.column>{{ __('Gerät / Platz') }}</flux:table.column>
                <flux:table.column>{{ __('Grund') }}</flux:table.column>
                <flux:table.column align="end">kWh</flux:table.column>
                <flux:table.column>{{ __('Hinweis') }}</flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->recent as $reading)
                    <flux:table.row :key="'r-'.$reading->id">
                        <flux:table.cell class="whitespace-nowrap">{{ $reading->localTs()?->format('d.m.Y H:i') ?? '–' }}</flux:table.cell>
                        <flux:table.cell>{{ $reading->device->name }} · {{ $reading->slot }} {{ $reading->meter_name ? '('.$reading->meter_name.')' : '' }}</flux:table.cell>
                        <flux:table.cell>{{ ['plan' => __('Plan (00:00)'), 'start' => __('Einschalten'), 'reczny' => __('Hand')][$reading->reason] ?? $reading->reason }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((float) $reading->kwh, 2, ',', '.') }}</flux:table.cell>
                        <flux:table.cell>
                            @if ($reading->needs_review)
                                <flux:badge size="sm" color="amber">{{ __('Zeit unbekannt – prüfen') }}</flux:badge>
                            @elseif ($reading->ts_reconstructed)
                                <flux:badge size="sm" color="zinc">{{ __('Zeit rekonstruiert') }}</flux:badge>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    @endif

    <flux:modal name="device-form" class="md:w-md">
        <form wire:submit="saveDevice" class="space-y-5">
            <flux:heading size="lg">{{ __('Analysator hinzufügen') }}</flux:heading>
            <flux:input wire:model="name" :label="__('Gerätename')" :description="__('Genau wie im Analysator eingestellt (max. 31 Zeichen), z. B. analizator-1.')" maxlength="31" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Anlegen') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="device-token" class="md:w-xl">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Zugangsdaten für :device', ['device' => $shownDevice]) }}</flux:heading>
            <flux:callout icon="exclamation-triangle" color="amber" :text="__('Der Token wird nur jetzt angezeigt. Tragen Sie URL und Token im Analysator ein.')" />
            <flux:input :label="__('URL')" :value="route('api.analyzer.readings')" readonly copyable />
            <flux:input :label="__('Token')" :value="$shownToken" readonly copyable class="font-mono" />
            <div class="flex justify-end">
                <flux:modal.close><flux:button variant="primary">{{ __('Fertig') }}</flux:button></flux:modal.close>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="slot-form" class="md:w-lg">
        <form wire:submit="saveSlot" class="space-y-5">
            <flux:heading size="lg">{{ __('Platz einem Messpunkt zuordnen') }}</flux:heading>
            <flux:text>{{ __('Je Tag wird der früheste Stand (Plan-Ablesung um 00:00) als Zählerstand des Messpunkts übernommen.') }}</flux:text>
            <flux:select wire:model="meter_id" :label="__('Messpunkt / Zähler')">
                <flux:select.option value="">{{ __('– nicht zugeordnet –') }}</flux:select.option>
                @foreach ($this->meters as $meter)
                    <flux:select.option :value="$meter->id">{{ $meter->number }}{{ $meter->is_analyzer ? ' ['.__('Analysator').']' : '' }}{{ $meter->location ? ' – '.$meter->location : '' }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="since" :label="__('Ablesungen übernehmen ab')" type="date" required />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

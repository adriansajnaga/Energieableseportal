<?php

use App\Enums\Medium;
use App\Livewire\Concerns\WithWorkingMonth;
use App\Models\AnalyzerSlot;
use App\Models\Meter;
use App\Services\ConsumptionTree;
use App\Services\MeterService;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Title('Leitungsschema')] class extends Component {
    use WithWorkingMonth;

    /** Vorgeschalteter Zähler je Zähler (ID als String, Hauptzähler = direkt). */
    public array $feeds = [];

    // Neuer Messpunkt
    public string $number = '';
    public string $location = '';
    public string $parent_id = '';
    public string $feed_id = '';
    public string $factor = '1';
    public string $initial_value = '';
    public string $starts_on = '';

    public function mount(): void
    {
        $this->loadFeeds();
    }

    #[Computed]
    public function roots()
    {
        return app(ConsumptionTree::class)->build($this->period());
    }

    /** Aktive Stromzähler gruppiert nach Hauptzähler (für die Zuordnung). */
    #[Computed]
    public function groups(): Collection
    {
        $meters = Meter::query()->electricity()->active()->with('tenant')->orderByDesc('is_analyzer')->orderBy('number')->get();

        return $meters->where('is_main', true)->map(fn (Meter $main) => [
            'main' => $main,
            'meters' => $meters->where('is_main', false)->where('parent_id', $main->id)->values(),
        ])->values();
    }

    /** Aktive Stromzähler ohne Hauptzähler – erscheinen sonst nicht im Schema. */
    #[Computed]
    public function unassigned(): Collection
    {
        return Meter::query()->electricity()->active()->where('is_main', false)->whereNull('parent_id')
            ->with('tenant')->orderByDesc('is_analyzer')->orderBy('number')->get();
    }

    #[Computed]
    public function analyzerMeters(): Collection
    {
        return Meter::query()->electricity()->analyzer()->active()->with(['feed', 'parent'])->orderBy('number')->get();
    }

    #[Computed]
    public function pointSlots(): Collection
    {
        return AnalyzerSlot::query()->whereNotNull('meter_id')->with('device')->get()->keyBy('meter_id');
    }

    /**
     * Mögliche vorgeschaltete Zähler: der Hauptzähler (direkt) und alle anderen Zähler desselben Hauptzählers,
     * außer dem Zähler selbst und den Zählern, die hinter ihm hängen.
     */
    public function feedOptions(Meter $meter, Meter $main, Collection $meters): Collection
    {
        $upstream = $meters->mapWithKeys(fn (Meter $m) => [$m->id => $m->feed_id]);
        $behind = function (int $id) use ($upstream, $meter): bool {
            $seen = [];
            while ($id && ! isset($seen[$id])) {
                if ($id === $meter->id) {
                    return true;
                }
                $seen[$id] = true;
                $id = (int) ($upstream[$id] ?? 0);
            }

            return false;
        };

        return collect([['id' => $main->id, 'label' => __('direkt am Hauptzähler :number', ['number' => $main->number])]])
            ->merge($meters
                ->reject(fn (Meter $m) => $behind($m->id))
                ->map(fn (Meter $m) => [
                    'id' => $m->id,
                    'label' => $m->number.($m->is_analyzer ? ' ['.__('Analysator').']' : '').($m->location ? ' – '.$m->location : ''),
                ]));
    }

    public function updatedFeeds($value, $key): void
    {
        Gate::authorize('manage');
        $meter = Meter::findOrFail((int) $key);

        try {
            app(MeterService::class)->setFeed($meter, $value ? Meter::find((int) $value) : null);
            Flux::toast(__('Zuordnung gespeichert.'), variant: 'success');
        } catch (RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');
        }

        $this->loadFeeds();
        unset($this->roots, $this->groups, $this->unassigned, $this->analyzerMeters);
    }

    public function createMeasuringPoint(): void
    {
        Gate::authorize('manage');
        $this->reset(['number', 'location', 'feed_id', 'initial_value']);
        $this->factor = '1';
        $this->parent_id = (string) ($this->groups->first()['main']->id ?? '');
        $this->starts_on = now()->toDateString();
        $this->resetValidation();
        Flux::modal('measuring-point')->show();
    }

    public function updatedParentId(): void
    {
        $this->feed_id = '';
    }

    public function saveMeasuringPoint(MeterService $service): void
    {
        Gate::authorize('manage');

        $data = $this->validate([
            'number' => ['required', 'string', 'max:255'],
            'location' => ['required', 'string', 'max:255'],
            'parent_id' => ['required', Rule::exists('meters', 'id')->where('is_main', true)->where('medium', Medium::Electricity->value)],
            'feed_id' => ['nullable', Rule::exists('meters', 'id')->where('parent_id', $this->parent_id)],
            'factor' => ['required', 'integer', 'min:1', 'max:10000'],
            'initial_value' => ['required', 'integer', 'min:0'],
            'starts_on' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $meter = $service->create([
            'number' => $data['number'],
            'location' => $data['location'],
            'medium' => Medium::Electricity,
            'is_analyzer' => true,
            'parent_id' => $data['parent_id'],
            'factor' => $data['factor'],
            'installed_on' => $data['starts_on'],
        ], (int) $data['initial_value'], CarbonImmutable::parse($data['starts_on']), Auth::user());

        if ($this->feed_id) {
            $service->setFeed($meter, Meter::find((int) $this->feed_id));
        }

        $this->loadFeeds();
        unset($this->roots, $this->groups, $this->analyzerMeters);
        Flux::modal('measuring-point')->close();
        Flux::toast(__('Messpunkt angelegt. Ordnen Sie jetzt die Zähler hinter diesem Abzweig zu.'), variant: 'success');
    }

    private function loadFeeds(): void
    {
        $this->feeds = Meter::query()->electricity()->active()->where('is_main', false)
            ->get(['id', 'feed_id', 'parent_id'])
            ->mapWithKeys(fn (Meter $m) => [$m->id => (string) ($m->feed_id ?? $m->parent_id)])
            ->all();
    }
}; ?>

<div>
    <x-page-header :title="__('Leitungsschema')" :subtitle="__('Hauptzähler, Abzweige mit Analysator-Messpunkten und die Zähler dahinter – für die Suche nach Verlusten')">
        <x-month-switcher :period="$this->period()" />
        @can('manage')
            <flux:button variant="primary" icon="plus" wire:click="createMeasuringPoint">{{ __('Messpunkt hinzufügen') }}</flux:button>
        @endcan
    </x-page-header>

    <flux:callout icon="information-circle" class="mb-6">
        <flux:callout.text>
            {{ __('Ein Messpunkt ist ein zusätzlicher Zähler des Analysators auf einem Abzweig vom Hauptzähler. Ordnen Sie unten die Endzähler dem Abzweig zu, über den sie versorgt werden. Die Differenz zwischen Messpunkt und Summe der Zähler dahinter zeigt, auf welcher Leitung Energie verloren geht. Messpunkte werden nicht abgerechnet.') }}
        </flux:callout.text>
    </flux:callout>

    <x-consumption-tree :roots="$this->roots" />

    <flux:heading size="lg" class="mt-8">{{ __('Zuordnung der Zähler') }}</flux:heading>
    <flux:text class="mb-3">{{ __('Für jeden Zähler: über welchen Abzweig (Messpunkt) oder direkt über welchen Hauptzähler er versorgt wird.') }}</flux:text>

    <div class="space-y-4">
        @if ($this->unassigned->isNotEmpty())
            <flux:card class="border-amber-300! dark:border-amber-700!">
                <flux:heading>{{ __('Zähler ohne Hauptzähler') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Diese Zähler hängen an keinem Hauptzähler und erscheinen deshalb nicht im Schema. Wählen Sie den Hauptzähler aus.') }}</flux:text>
                <flux:table class="mt-2">
                    <flux:table.rows>
                        @foreach ($this->unassigned as $meter)
                            <flux:table.row :key="'unassigned-'.$meter->id">
                                <flux:table.cell variant="strong">
                                    <flux:link :href="route('meters.show', $meter)" wire:navigate>{{ $meter->number }}</flux:link>
                                    @if ($meter->is_analyzer) <flux:badge size="sm" color="violet" class="ms-1">{{ __('Analysator') }}</flux:badge> @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ collect([$meter->tenant?->name, $meter->location])->filter()->join(' · ') ?: '–' }}</flux:table.cell>
                                <flux:table.cell class="min-w-64">
                                    @can('manage')
                                        <flux:select size="sm" wire:model.live="feeds.{{ $meter->id }}">
                                            <flux:select.option value="">{{ __('– Hauptzähler wählen –') }}</flux:select.option>
                                            @foreach ($this->groups as $group)
                                                <flux:select.option :value="$group['main']->id">{{ __('Hauptzähler :number', ['number' => $group['main']->number]) }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @endif

        @forelse ($this->groups as $group)
            @php($candidates = $group['meters'])
            <flux:card wire:key="group-{{ $group['main']->id }}">
                <flux:heading>{{ __('Hauptzähler :number', ['number' => $group['main']->number]) }} <span class="font-normal text-zinc-500">{{ $group['main']->location }}</span></flux:heading>
                <flux:table class="mt-2">
                    <flux:table.columns>
                        <flux:table.column>{{ __('Zähler') }}</flux:table.column>
                        <flux:table.column>{{ __('Mieter / Ort') }}</flux:table.column>
                        <flux:table.column>{{ __('Versorgt über') }}</flux:table.column>
                    </flux:table.columns>
                    <flux:table.rows>
                        @forelse ($candidates as $meter)
                            <flux:table.row :key="'feed-'.$meter->id">
                                <flux:table.cell variant="strong">
                                    <flux:link :href="route('meters.show', $meter)" wire:navigate>{{ $meter->number }}</flux:link>
                                    @if ($meter->is_analyzer) <flux:badge size="sm" color="violet" class="ms-1">{{ __('Analysator') }}</flux:badge> @endif
                                </flux:table.cell>
                                <flux:table.cell>{{ collect([$meter->tenant?->name, $meter->location])->filter()->join(' · ') ?: '–' }}</flux:table.cell>
                                <flux:table.cell class="min-w-64">
                                    @can('manage')
                                        <flux:select size="sm" wire:model.live="feeds.{{ $meter->id }}">
                                            @foreach ($this->feedOptions($meter, $group['main'], $candidates->where('id', '!=', $meter->id)) as $option)
                                                <flux:select.option :value="$option['id']">{{ $option['label'] }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        {{ $meter->feed?->number ?? __('direkt am Hauptzähler :number', ['number' => $group['main']->number]) }}
                                    @endcan
                                </flux:table.cell>
                            </flux:table.row>
                        @empty
                            <flux:table.row>
                                <flux:table.cell colspan="3" class="text-center text-zinc-500">{{ __('Keine Zähler an diesem Hauptzähler.') }}</flux:table.cell>
                            </flux:table.row>
                        @endforelse
                    </flux:table.rows>
                </flux:table>
            </flux:card>
        @empty
            <flux:callout icon="information-circle" :text="__('Es gibt noch keinen aktiven Hauptzähler für Strom.')" />
        @endforelse
    </div>

    <flux:heading size="lg" class="mt-8">{{ __('Analysator-Messpunkte') }}</flux:heading>
    <flux:table class="mt-2">
        <flux:table.columns>
            <flux:table.column>{{ __('Messpunkt') }}</flux:table.column>
            <flux:table.column>{{ __('Abzweig / Ort') }}</flux:table.column>
            <flux:table.column>{{ __('Versorgt über') }}</flux:table.column>
            <flux:table.column>{{ __('Analysator') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Letzter Stand') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->analyzerMeters as $meter)
                @php($last = $meter->latestReading())
                <flux:table.row :key="'mp-'.$meter->id">
                    <flux:table.cell variant="strong"><flux:link :href="route('meters.show', $meter)" wire:navigate>{{ $meter->number }}</flux:link></flux:table.cell>
                    <flux:table.cell>{{ $meter->location }}</flux:table.cell>
                    <flux:table.cell>{{ $meter->feed?->number ?? $meter->parent?->number ?? '–' }}</flux:table.cell>
                    <flux:table.cell>
                        @if ($assigned = $this->pointSlots->get($meter->id))
                            {{ $assigned->device->name }} · {{ __('Platz') }} {{ $assigned->slot }}
                        @else
                            <span class="text-zinc-500">{{ __('manuelle Ablesung') }}</span>
                        @endif
                    </flux:table.cell>
                    <flux:table.cell align="end">{{ $last ? number_format($last->value, 0, ',', '.').' kWh · '.$last->read_on->format('d.m.Y') : '–' }}</flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="5" class="text-center text-zinc-500">{{ __('Noch keine Messpunkte angelegt.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <flux:modal name="measuring-point" class="md:w-xl">
        <form wire:submit="saveMeasuringPoint" class="space-y-5">
            <flux:heading size="lg">{{ __('Messpunkt hinzufügen') }}</flux:heading>
            <flux:text>{{ __('Zusätzlicher Zähler (z. B. F&F LE-03M am Analysator) auf einem Abzweig. Er wird abgelesen, aber nicht abgerechnet.') }}</flux:text>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="number" :label="__('Zählernummer / Bezeichnung')" placeholder="AN-1" required />
                <flux:input wire:model="location" :label="__('Abzweig / Leitung')" :placeholder="__('z. B. Abzweig Halle 1–6')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="parent_id" :label="__('Hauptzähler')" required>
                    @foreach ($this->groups as $group)
                        <flux:select.option :value="$group['main']->id">{{ $group['main']->number }} {{ $group['main']->location ? '('.$group['main']->location.')' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="feed_id" :label="__('Versorgt über')">
                    <flux:select.option value="">{{ __('direkt am Hauptzähler') }}</flux:select.option>
                    @foreach ($this->groups->firstWhere('main.id', (int) $parent_id)['meters'] ?? [] as $meter)
                        @if ($meter->is_analyzer)
                            <flux:select.option :value="$meter->id">{{ $meter->number }} – {{ $meter->location }}</flux:select.option>
                        @endif
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:input wire:model="factor" :label="__('Zählerfaktor')" type="number" min="1" required />
                <flux:input wire:model="initial_value" :label="__('Anfangsstand (kWh)')" type="number" min="0" required />
                <flux:input wire:model="starts_on" :label="__('Eingebaut am')" type="date" required />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Abbrechen') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Speichern') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>

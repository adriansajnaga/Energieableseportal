<?php

use App\Enums\Medium;
use App\Livewire\Concerns\WithWorkingMonth;
use App\Models\AnalyzerSlot;
use App\Models\Meter;
use App\Models\SitePlan;
use App\Models\SitePlanMarker;
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
use Livewire\WithFileUploads;

new #[Title('Leitungsschema')] class extends Component {
    use WithFileUploads, WithWorkingMonth;

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

    // Rzut obiektu (Bild oder PDF)
    public $planFile = null;
    public string $planTitle = '';

    // Zähler auf einem Plan markieren
    public ?int $markingPlanId = null;
    public string $markMeterId = '';

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
        return Meter::query()->electricity()->analyzer()->with(['feed', 'parent'])->orderByDesc('is_active')->orderBy('number')->get();
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

    #[Computed]
    public function plans(): Collection
    {
        return SitePlan::query()->with('markers.meter.tenant')->orderBy('position')->orderBy('id')->get();
    }

    /** Zähler, die auf einem Plan markiert werden können (Haupt-, Messpunkt-, Unter- und Wasserzähler). */
    #[Computed]
    public function markableMeters(): Collection
    {
        return Meter::query()->active()->with('tenant')
            ->orderByDesc('is_main')->orderByDesc('is_analyzer')->orderByRaw("medium = 'electricity' desc")->orderBy('number')
            ->get();
    }

    /** Verbrauch und Differenz des Monats je Zähler für die Hinweise auf dem Plan. */
    #[Computed]
    public function nodeInfo(): array
    {
        return ConsumptionTree::flatten($this->roots)
            ->filter(fn (array $node) => $node['meter'])
            ->mapWithKeys(fn (array $node) => [$node['meter']->id => [
                'kwh' => $node['kwh'] ?? $node['effective_kwh'],
                'difference' => $node['difference'],
                'percent' => $node['percent'],
            ]])
            ->all();
    }

    public function startMarking(int $planId): void
    {
        Gate::authorize('manage');
        $this->markingPlanId = SitePlan::findOrFail($planId)->id;
        $this->markMeterId = (string) ($this->nextUnplacedMeter($planId)?->id ?? '');
    }

    public function stopMarking(): void
    {
        $this->reset(['markingPlanId', 'markMeterId']);
    }

    /** Klick auf eine Markierung im Bearbeitungsmodus: diesen Zähler auswählen (nächster Klick verschiebt ihn). */
    public function selectMarkerMeter(int $meterId): void
    {
        $this->markMeterId = (string) $meterId;
    }

    public function placeMarker(int $planId, $x, $y): void
    {
        Gate::authorize('manage');
        abort_unless($planId === $this->markingPlanId && is_numeric($x) && is_numeric($y), 422);

        $meter = Meter::query()->active()->find((int) $this->markMeterId);
        if (! $meter) {
            Flux::toast(__('Bitte zuerst einen Zähler auswählen.'), variant: 'warning');

            return;
        }

        SitePlanMarker::query()->updateOrCreate(
            ['site_plan_id' => $planId, 'meter_id' => $meter->id],
            ['x' => round(min(100, max(0, (float) $x)), 3), 'y' => round(min(100, max(0, (float) $y)), 3)],
        );

        // Weiter mit dem nächsten noch nicht markierten Zähler.
        $this->markMeterId = (string) ($this->nextUnplacedMeter($planId)?->id ?? $meter->id);
        unset($this->plans);
    }

    public function removeMarker(int $markerId): void
    {
        Gate::authorize('manage');
        SitePlanMarker::findOrFail($markerId)->delete();
        unset($this->plans);
    }

    /** Darstellung einer Markierung: Farbe nach Zählerart, Rand nach Differenz des Messpunkts, Hinweistext. */
    public function markerView(SitePlanMarker $marker): array
    {
        $m = $marker->meter;
        $info = $this->nodeInfo[$m->id] ?? null;

        $dot = match (true) {
            $m->is_main => 'bg-amber-500',
            $m->is_analyzer => 'bg-violet-600',
            $m->isWater() => 'bg-sky-500',
            default => 'bg-emerald-600',
        };
        $ring = match ($m->is_analyzer && $info ? ConsumptionTree::differenceColor($info['percent']) : null) {
            'red' => 'ring-red-500',
            'amber' => 'ring-amber-500',
            'green' => 'ring-green-500',
            default => 'ring-zinc-300',
        };
        $tip = collect([
            $m->number.' – '.$m->typeLabel(),
            $m->tenant?->name,
            $m->location,
            $info && $info['kwh'] !== null ? number_format($info['kwh'], 0, ',', '.').' kWh '.$this->period()->format('m/Y') : null,
            $info && $info['difference'] !== null
                ? __('Differenz').': '.number_format($info['difference'], 0, ',', '.').' kWh ('.number_format($info['percent'] ?? 0, 1, ',', '.').' %)'
                : null,
        ])->filter()->join("
");

        return [
            'dot' => $dot,
            'tip' => $tip,
            'style' => 'left: '.number_format($marker->x, 3, '.', '').'%; top: '.number_format($marker->y, 3, '.', '').'%',
            'classes' => 'absolute z-10 flex -translate-x-1/2 -translate-y-1/2 items-center gap-1 whitespace-nowrap rounded-full bg-white/95 px-1.5 py-0.5 text-[11px] leading-4 font-semibold text-zinc-900 shadow-md ring-2 hover:z-20 '.$ring,
        ];
    }

    private function nextUnplacedMeter(int $planId): ?Meter
    {
        $placed = SitePlanMarker::query()->where('site_plan_id', $planId)->pluck('meter_id')->all();

        return $this->markableMeters->first(fn (Meter $m) => ! in_array($m->id, $placed, true));
    }

    /** Versehentlich angelegten Messpunkt löschen; Zähler dahinter hängen danach am vorgeschalteten Knoten. */
    public function deleteMeasuringPoint(int $meterId): void
    {
        Gate::authorize('manage');
        $meter = Meter::findOrFail($meterId);

        try {
            app(MeterService::class)->deleteMeasuringPoint($meter);
        } catch (RuntimeException $e) {
            Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->loadFeeds();
        unset($this->roots, $this->groups, $this->unassigned, $this->analyzerMeters);
        Flux::toast(__('Messpunkt :number gelöscht.', ['number' => $meter->number]), variant: 'success');
    }

    public function uploadPlan(): void
    {
        Gate::authorize('manage');

        $this->validate([
            'planFile' => ['required', 'file', 'mimes:'.implode(',', SitePlan::MIMES), 'max:12288'],
            'planTitle' => ['nullable', 'string', 'max:255'],
        ]);

        $name = $this->planFile->getClientOriginalName();

        SitePlan::create([
            'title' => trim($this->planTitle) !== '' ? trim($this->planTitle) : pathinfo($name, PATHINFO_FILENAME),
            'path' => $this->planFile->store('plans', 'local'),
            'original_name' => $name,
            'mime' => $this->planFile->getMimeType(),
            'size' => $this->planFile->getSize(),
            'position' => (int) SitePlan::max('position') + 1,
            'uploaded_by' => Auth::id(),
        ]);

        $this->reset(['planFile', 'planTitle']);
        unset($this->plans);
        Flux::toast(__('Plan hochgeladen.'), variant: 'success');
    }

    public function deletePlan(int $planId): void
    {
        Gate::authorize('manage');
        SitePlan::findOrFail($planId)->delete();
        unset($this->plans);
        Flux::toast(__('Plan gelöscht.'));
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

    <flux:callout icon="information-circle" class="mb-8">
        <flux:callout.text>
            {{ __('Ein Messpunkt ist ein zusätzlicher Zähler des Analysators auf einem Abzweig vom Hauptzähler. Ordnen Sie unten die Endzähler dem Abzweig zu, über den sie versorgt werden. Die Differenz zwischen Messpunkt und Summe der Zähler dahinter zeigt, auf welcher Leitung Energie verloren geht. Messpunkte werden nicht abgerechnet.') }}
        </flux:callout.text>
    </flux:callout>

    <x-consumption-tree :roots="$this->roots" />

    <flux:heading size="lg" class="mt-12">{{ __('Zuordnung der Zähler') }}</flux:heading>
    <flux:text class="mb-4 mt-1">{{ __('Für jeden Zähler: über welchen Abzweig (Messpunkt) oder direkt über welchen Hauptzähler er versorgt wird.') }}</flux:text>

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

    <flux:heading size="lg" class="mt-12">{{ __('Analysator-Messpunkte') }}</flux:heading>
    <flux:table class="mt-3">
        <flux:table.columns>
            <flux:table.column>{{ __('Messpunkt') }}</flux:table.column>
            <flux:table.column>{{ __('Abzweig / Ort') }}</flux:table.column>
            <flux:table.column>{{ __('Versorgt über') }}</flux:table.column>
            <flux:table.column>{{ __('Analysator') }}</flux:table.column>
            <flux:table.column align="end">{{ __('Letzter Stand') }}</flux:table.column>
            <flux:table.column></flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @forelse ($this->analyzerMeters as $meter)
                @php($last = $meter->latestReading())
                <flux:table.row :key="'mp-'.$meter->id">
                    <flux:table.cell variant="strong">
                        <flux:link :href="route('meters.show', $meter)" wire:navigate>{{ $meter->number }}</flux:link>
                        @unless ($meter->is_active) <flux:badge size="sm" color="zinc" class="ms-1">{{ __('inaktiv') }}</flux:badge> @endunless
                    </flux:table.cell>
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
                    <flux:table.cell align="end">
                        @can('manage')
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteMeasuringPoint({{ $meter->id }})" :tooltip="__('Messpunkt löschen')"
                                wire:confirm="{{ __('Messpunkt :number löschen? Die Zähler dahinter hängen danach direkt am vorgeschalteten Zähler, die Ablesungen des Messpunkts werden gelöscht.', ['number' => $meter->number]) }}" />
                        @endcan
                    </flux:table.cell>
                </flux:table.row>
            @empty
                <flux:table.row>
                    <flux:table.cell colspan="6" class="text-center text-zinc-500">{{ __('Noch keine Messpunkte angelegt.') }}</flux:table.cell>
                </flux:table.row>
            @endforelse
        </flux:table.rows>
    </flux:table>

    <div class="mt-12 flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="lg">{{ __('Pläne des Objekts') }}</flux:heading>
            <flux:text>{{ __('Grundriss / Lageplan mit Zählern und Leitungen (PDF oder Bild).') }}</flux:text>
        </div>
    </div>

    @can('manage')
        <form wire:submit="uploadPlan" class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <flux:input wire:model="planFile" type="file" :label="__('Datei (PDF, PNG, JPG, max. 12 MB)')" accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,application/pdf,image/*" />
            </div>
            <div class="min-w-64 flex-1">
                <flux:input wire:model="planTitle" :label="__('Bezeichnung (optional)')" :placeholder="__('z. B. Grundriss Halle 1–6')" />
            </div>
            <flux:button type="submit" variant="primary" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="planFile,uploadPlan">{{ __('Hochladen') }}</flux:button>
        </form>
        <div wire:loading wire:target="planFile" class="mt-2 text-sm text-zinc-500">{{ __('Datei wird hochgeladen …') }}</div>
    @endcan

    <div class="mt-4 space-y-6">
        @forelse ($this->plans as $plan)
            <flux:card wire:key="plan-{{ $plan->id }}" class="space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <flux:heading>{{ $plan->title }}</flux:heading>
                        <flux:text size="sm">{{ $plan->original_name }} · {{ $plan->size >= 1048576 ? number_format($plan->size / 1048576, 1, ',', '.').' MB' : max(1, round($plan->size / 1024)).' KB' }} · {{ $plan->created_at->format('d.m.Y') }}</flux:text>
                    </div>
                    <div class="flex gap-1">
                        <flux:button size="sm" icon="arrow-top-right-on-square" :href="$plan->url()" target="_blank">{{ __('Öffnen') }}</flux:button>
                        @can('manage')
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deletePlan({{ $plan->id }})" wire:confirm="{{ __('Plan „:title“ löschen?', ['title' => $plan->title]) }}" />
                        @endcan
                    </div>
                </div>
                @php($marking = $markingPlanId === $plan->id)

                @if ($marking)
                    <div class="space-y-3 rounded-lg border border-blue-300 bg-blue-50 p-3 dark:border-blue-800 dark:bg-blue-950/40">
                        <div class="flex flex-wrap items-end gap-3">
                            <div class="min-w-64 flex-1">
                                <flux:select wire:model.live="markMeterId" :label="__('Zähler zum Markieren')">
                                    @php($placed = $plan->markers->pluck('meter_id')->all())
                                    @foreach ($this->markableMeters as $meter)
                                        <flux:select.option :value="$meter->id">{{ in_array($meter->id, $placed, true) ? '✓ ' : '' }}{{ $meter->number }} – {{ $meter->typeLabel() }}{{ $meter->tenant ? ' – '.$meter->tenant->name : ($meter->location ? ' – '.$meter->location : '') }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <flux:button variant="primary" icon="check" wire:click="stopMarking">{{ __('Fertig') }}</flux:button>
                        </div>
                        <flux:text size="sm">{{ __('Zähler auswählen und auf die Stelle im Plan klicken. Danach ist automatisch der nächste Zähler ausgewählt. Ein bereits markierter Zähler wird beim nächsten Klick verschoben; ein Klick auf eine Markierung wählt sie aus.') }}</flux:text>
                        @if ($plan->markers->isNotEmpty())
                            <div class="flex flex-wrap gap-1.5">
                                @foreach ($plan->markers->sortBy('meter.number') as $marker)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-white px-2 py-0.5 text-xs shadow-xs dark:bg-zinc-800" wire:key="chip-{{ $marker->id }}">
                                        {{ $marker->meter->number }}
                                        <button type="button" class="text-zinc-400 hover:text-red-600" wire:click="removeMarker({{ $marker->id }})" title="{{ __('Markierung entfernen') }}">&times;</button>
                                    </span>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif

                @php($pdfLib = ['module' => asset('vendor/pdfjs/pdf.min.js'), 'worker' => asset('vendor/pdfjs/pdf.worker.min.js'), 'fonts' => asset('vendor/pdfjs/standard_fonts').'/'])
                <div @class(['relative overflow-hidden rounded-lg border border-zinc-200 bg-white select-none dark:border-zinc-700', 'cursor-crosshair ring-2 ring-blue-400' => $marking])
                    x-data
                    x-on:click="if (! {{ $marking ? 'true' : 'false' }}) return; const r = $el.getBoundingClientRect(); $wire.placeMarker({{ $plan->id }}, ($event.clientX - r.left) / r.width * 100, ($event.clientY - r.top) / r.height * 100)">
                    @if ($plan->isImage())
                        <img src="{{ $plan->url() }}" alt="{{ $plan->title }}" class="block w-full" draggable="false">
                    @else
                        <div wire:ignore x-data="{ state: 'loading' }"
                            x-init="renderPdfPlan($refs.canvas, @js($plan->url()), @js($pdfLib)).then(() => state = 'ready').catch(() => state = 'error')">
                            <canvas x-ref="canvas" class="block w-full" x-show="state === 'ready'"></canvas>
                            <div x-show="state === 'loading'" class="p-10 text-center text-sm text-zinc-500">{{ __('Plan wird geladen …') }}</div>
                            <div x-show="state === 'error'" x-cloak class="p-10 text-center text-sm text-amber-700">{{ __('Der PDF-Plan kann hier nicht angezeigt werden. Bitte mit „Öffnen“ ansehen.') }}</div>
                        </div>
                    @endif

                    @foreach ($plan->markers as $marker)
                        @php($m = $marker->meter)
                        @php($mv = $this->markerView($marker))
                        @if ($marking)
                            <button type="button" wire:key="marker-{{ $marker->id }}" title="{{ $mv['tip'] }}" style="{{ $mv['style'] }}"
                                x-on:click.stop="$wire.selectMarkerMeter({{ $m->id }})"
                                @class([$mv['classes'], 'outline-3 outline-blue-500' => (string) $m->id === $markMeterId])>
                                <span class="size-2.5 shrink-0 rounded-full {{ $mv['dot'] }}"></span>{{ $m->number }}
                            </button>
                        @else
                            <a href="{{ route('meters.show', $m) }}" wire:navigate wire:key="marker-{{ $marker->id }}" title="{{ $mv['tip'] }}" style="{{ $mv['style'] }}" class="{{ $mv['classes'] }}">
                                <span class="size-2.5 shrink-0 rounded-full {{ $mv['dot'] }}"></span>{{ $m->number }}
                            </a>
                        @endif
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-zinc-500">
                        <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-full bg-amber-500"></span>{{ __('Hauptzähler') }}</span>
                        <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-full bg-violet-600"></span>{{ __('Analysator-Messpunkt') }}</span>
                        <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-full bg-emerald-600"></span>{{ __('Unterzähler') }}</span>
                        <span class="inline-flex items-center gap-1"><span class="size-2.5 rounded-full bg-sky-500"></span>{{ __('Wasserzähler') }}</span>
                        <span>{{ __('Rand am Messpunkt: Differenz im :month (rot ab 15 %, gelb ab 5 %)', ['month' => $this->period()->format('m/Y')]) }}</span>
                    </div>
                    @can('manage')
                        @unless ($marking)
                            <flux:button size="sm" icon="map-pin" wire:click="startMarking({{ $plan->id }})">{{ __('Zähler markieren') }}</flux:button>
                        @endunless
                    @endcan
                </div>
            </flux:card>
        @empty
            <flux:text class="text-zinc-500">{{ __('Noch kein Plan hochgeladen.') }}</flux:text>
        @endforelse
    </div>

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

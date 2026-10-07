<?php

namespace App\Services;

use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Verbrauchsverteilung eines Monats als Baum nach dem Leitungsschema:
 * Hauptzähler -> Abzweige (Analysator-Messpunkte) -> Endzähler. Je Knoten mit Unterzählern wird die Differenz
 * (Verbrauch des Knotens minus Summe der Unterzähler) ausgewiesen – dort geht Energie verloren oder ein Zähler fehlt.
 */
class ConsumptionTree
{
    public const INVOICE = 'invoice';

    public function __construct(private MeterConsumption $consumption) {}

    /**
     * @return Collection<int, array<string, mixed>> Wurzelknoten (Hauptzähler, ggf. "Ohne Zuordnung")
     */
    public function build(CarbonInterface $month): Collection
    {
        $period = CarbonImmutable::parse($month)->startOfMonth();

        $meters = Meter::query()->electricity()->existingIn($period)->with('tenant')->orderBy('number')->get()->keyBy('id');
        $this->consumption->preload($meters->keys()->all(), $period);

        $settled = Settlement::query()->effective()
            ->whereDate('period', $period->toDateString())
            ->whereIn('meter_id', $meters->keys())
            ->get(['meter_id', 'billed_kwh'])
            ->groupBy('meter_id')
            ->map(fn (Collection $rows) => (int) $rows->sum('billed_kwh'));

        $supplier = ElectricityPrice::query()
            ->whereDate('month', $period->toDateString())
            ->pluck('consumption_kwh', 'meter_id');

        $children = [];
        foreach ($meters as $meter) {
            if ($meter->is_main) {
                continue;
            }

            $upstream = $meter->upstreamId();
            // Vorgeschalteter Zähler im Monat nicht (mehr) eingebaut: am Hauptzähler anhängen.
            if ($upstream && ! $meters->has($upstream) && $meters->has($meter->parent_id)) {
                $upstream = $meter->parent_id;
            }

            $children[$meters->has($upstream) ? $upstream : 0][] = $meter->id;
        }

        $visited = [];
        $node = function (int $id) use (&$node, &$visited, $meters, $children, $settled, $supplier, $period): array {
            $visited[$id] = true;
            $meter = $meters[$id];

            $billable = ! $meter->is_main && ! $meter->is_analyzer;
            $result = $this->consumption->forMonth($meter, $period, $billable ? $settled->get($id) : null);

            if ($meter->is_main && $result['kwh'] === null && $supplier->has($id)) {
                $result = ['kwh' => (int) round((float) $supplier[$id]), 'source' => self::INVOICE];
            }

            $kids = collect($children[$id] ?? [])
                ->reject(fn (int $child) => isset($visited[$child]))
                ->map(fn (int $child) => $node($child))
                ->values();

            return $this->node(
                key: 'm'.$id,
                meter: $meter,
                label: $meter->number,
                kwh: $result['kwh'],
                source: $result['source'],
                children: $kids,
                supplierKwh: $meter->is_main && $supplier->has($id) ? (int) round((float) $supplier[$id]) : null,
                period: $period,
            );
        };

        $roots = $meters->filter->is_main->keys()->map(fn (int $id) => $node($id))->values();

        // Zähler ohne gültigen Hauptzähler, danach solche in einem Kreis im Schema (feed_id gegenseitig).
        $orphans = collect();
        foreach ([...($children[0] ?? []), ...$meters->keys()->all()] as $id) {
            if (! isset($visited[$id]) && ! $meters[$id]->is_main) {
                $orphans->push($node($id));
            }
        }

        if ($orphans->isNotEmpty()) {
            $roots->push($this->node(
                key: 'orphans',
                meter: null,
                label: __('Ohne Zuordnung zu einem Hauptzähler'),
                kwh: null,
                source: MeterConsumption::NONE,
                children: $orphans,
                supplierKwh: null,
                period: $period,
            ));
        }

        return $roots;
    }

    /** Alle Knoten des Baums in einer flachen Liste (Tiefensuche). */
    public static function flatten(Collection $nodes): Collection
    {
        return $nodes->flatMap(fn (array $node) => collect([$node])->merge(self::flatten($node['children'])));
    }

    private function node(string $key, ?Meter $meter, string $label, ?int $kwh, string $source, Collection $children, ?int $supplierKwh, CarbonImmutable $period): array
    {
        $childrenKwh = $children->isEmpty() ? null : (int) $children->sum(fn (array $c) => $c['kwh'] ?? 0);
        $missing = $children->filter(fn (array $c) => $c['kwh'] === null)->count();
        $difference = $kwh !== null && $childrenKwh !== null ? $kwh - $childrenKwh : null;

        return [
            'key' => $key,
            'meter' => $meter,
            'label' => $label,
            'kwh' => $kwh,
            'source' => $source,
            'supplier_kwh' => $supplierKwh,
            'children' => $children,
            'children_kwh' => $childrenKwh,
            'children_missing' => $missing,
            'difference' => $difference,
            'percent' => $difference !== null && $kwh > 0 ? round($difference / $kwh * 100, 1) : null,
            'installed' => $meter?->installed_on && $meter->installed_on->isSameMonth($period) && $meter->installed_on->greaterThan($period),
            'removed' => $meter?->removed_on && $meter->removed_on->lessThan($period->addMonthNoOverflow()),
        ];
    }

    public static function sourceLabel(string $source): string
    {
        return $source === self::INVOICE ? __('Versorgerrechnung') : MeterConsumption::sourceLabel($source);
    }

    /** Farbe der Differenz: rot ab 15 %, gelb ab 5 % oder wenn die Unterzähler mehr zeigen als der Knoten. */
    public static function differenceColor(?float $percent): string
    {
        return match (true) {
            $percent === null => 'zinc',
            $percent >= 15 => 'red',
            $percent >= 5 || $percent < -2 => 'amber',
            default => 'green',
        };
    }
}

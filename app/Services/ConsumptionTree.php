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
 *
 * - Hauptzähler: immer der Verbrauch laut Versorgerrechnung (Strompreise), nie aus Ablesungen gerechnet.
 * - Messpunkte zeigen das aktuelle Schema auch in Monaten vor ihrem Einbau. Ohne eigene Messung für den ganzen
 *   Monat zählt für den übergeordneten Knoten die Summe der Zähler dahinter.
 */
class ConsumptionTree
{
    public const INVOICE = 'invoice';

    public const NO_INVOICE = 'no_invoice';

    public function __construct(private MeterConsumption $consumption) {}

    /**
     * @return Collection<int, array<string, mixed>> Wurzelknoten (Hauptzähler, ggf. "Ohne Zuordnung")
     */
    public function build(CarbonInterface $month): Collection
    {
        $period = CarbonImmutable::parse($month)->startOfMonth();

        $meters = Meter::query()->electricity()
            ->where(fn ($q) => $q->existingIn($period)->orWhere(fn ($q) => $q->where('is_analyzer', true)->where('is_active', true)))
            ->with('tenant')
            ->orderByDesc('is_analyzer')
            ->orderBy('number')
            ->get()
            ->keyBy('id');
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

            if ($meter->is_main) {
                // Hauptzähler: Verbrauch laut Rechnung des Versorgers (wird mit dem Strompreis erfasst).
                $result = $supplier->has($id)
                    ? ['kwh' => (int) round((float) $supplier[$id]), 'source' => self::INVOICE, 'partial' => false]
                    : ['kwh' => null, 'source' => self::NO_INVOICE, 'partial' => false];
            } else {
                $result = $this->consumption->forMonth($meter, $period, $meter->is_analyzer ? null : $settled->get($id));
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
                partial: $result['partial'],
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
                partial: false,
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

    private function node(string $key, ?Meter $meter, string $label, ?int $kwh, string $source, Collection $children, bool $partial, CarbonImmutable $period): array
    {
        $childrenKwh = $children->isEmpty() ? null : (int) $children->sum(fn (array $c) => $c['effective_kwh'] ?? 0);
        $missing = $children->filter(fn (array $c) => $c['effective_kwh'] === null)->count();

        // Differenz nur, wenn der Knoten den ganzen Monat gemessen hat.
        $measured = $kwh !== null && ! $partial;
        $difference = $measured && $childrenKwh !== null ? $kwh - $childrenKwh : null;

        // Wert für den übergeordneten Knoten: eigene Messung, sonst die (vollständige) Summe der Zähler dahinter.
        $effective = $children->isNotEmpty() && ! $measured ? ($missing === 0 ? $childrenKwh : null) : $kwh;

        return [
            'key' => $key,
            'meter' => $meter,
            'label' => $label,
            'kwh' => $kwh,
            'effective_kwh' => $effective,
            'source' => $source,
            'partial' => $partial,
            'children' => $children,
            'children_kwh' => $childrenKwh,
            'children_missing' => $missing,
            'difference' => $difference,
            'percent' => $difference !== null && $kwh > 0 ? round($difference / $kwh * 100, 1) : null,
            'not_installed' => $source === MeterConsumption::NOT_INSTALLED,
            'installed' => $meter?->installed_on && $meter->installed_on->isSameMonth($period) && $meter->installed_on->greaterThan($period),
            'removed' => $meter?->removed_on && $meter->removed_on->lessThan($period->addMonthNoOverflow()),
        ];
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            self::INVOICE => __('Versorgerrechnung'),
            self::NO_INVOICE => __('Versorgerrechnung fehlt'),
            default => MeterConsumption::sourceLabel($source),
        };
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

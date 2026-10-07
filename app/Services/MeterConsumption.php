<?php

namespace App\Services;

use App\Models\Meter;
use App\Models\Reading;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Monatsverbrauch eines Zählers "vom 1. bis zum 1." (bzw. ab Einbau / bis Ausbau) aus den freigegebenen Ablesungen.
 *
 * Stand am Stichtag: Ablesung an diesem Tag (früheste), sonst linear zwischen der Ablesung davor und danach
 * interpoliert, sonst aus dem letzten Tagesverbrauch hochgerechnet. Abgerechnete Zähler liefern die abgerechneten kWh,
 * damit die Verteilung zur Abrechnung passt. Ergebnis inkl. Zählerfaktor in ganzen kWh.
 */
class MeterConsumption
{
    public const MEASURED = 'measured';

    public const INTERPOLATED = 'interpolated';

    public const ESTIMATED = 'estimated';

    public const SETTLED = 'settled';

    public const NONE = 'none';

    public const NOT_INSTALLED = 'not_installed';

    /** @var array<int, Collection<int, Reading>> */
    private array $readings = [];

    /**
     * Ablesungen mehrerer Zähler auf einmal laden (vermeidet eine Abfrage je Zähler und Stichtag).
     *
     * @param  list<int>  $meterIds
     */
    public function preload(array $meterIds, CarbonInterface $month): void
    {
        $start = CarbonImmutable::parse($month)->startOfMonth();

        Reading::query()
            ->valid()
            ->whereIn('meter_id', $meterIds)
            ->whereBetween('read_on', [$start->subYear()->toDateString(), $start->addMonthNoOverflow()->addYear()->toDateString()])
            ->orderBy('read_on')
            ->orderBy('value')
            ->get(['id', 'meter_id', 'read_on', 'value'])
            ->groupBy('meter_id')
            ->each(fn (Collection $rows, $meterId) => $this->readings[(int) $meterId] = $rows->values());

        foreach ($meterIds as $id) {
            $this->readings[$id] ??= collect();
        }
    }

    /**
     * "partial": der Zähler war nicht den ganzen Monat eingebaut (Einbau/Ausbau im Monat).
     *
     * @return array{kwh: ?int, source: string, from: ?CarbonImmutable, to: ?CarbonImmutable, partial: bool}
     */
    public function forMonth(Meter $meter, CarbonInterface $month, ?int $settledKwh = null): array
    {
        $monthStart = CarbonImmutable::parse($month)->startOfMonth();
        $monthEnd = $monthStart->addMonthNoOverflow();
        $start = $monthStart;
        $end = $monthEnd;

        if (($meter->installed_on && $meter->installed_on->greaterThanOrEqualTo($monthEnd))
            || ($meter->removed_on && $meter->removed_on->lessThanOrEqualTo($monthStart))) {
            return ['kwh' => null, 'source' => self::NOT_INSTALLED, 'from' => null, 'to' => null, 'partial' => true];
        }

        if ($meter->installed_on && $meter->installed_on->greaterThan($start)) {
            $start = CarbonImmutable::parse($meter->installed_on);
        }
        if ($meter->removed_on && $meter->removed_on->lessThan($end)) {
            $end = CarbonImmutable::parse($meter->removed_on);
        }

        $partial = $start->greaterThan($monthStart) || $end->lessThan($monthEnd);

        if ($settledKwh !== null) {
            return ['kwh' => $settledKwh, 'source' => self::SETTLED, 'from' => $start, 'to' => $end, 'partial' => $partial];
        }

        $readings = $this->readings[$meter->id] ?? $this->load($meter, $start, $end);
        [$startValue, $startSource] = $this->valueAt($readings, $start);
        [$endValue, $endSource] = $this->valueAt($readings, $end);

        if ($startValue === null || $endValue === null) {
            return ['kwh' => null, 'source' => self::NONE, 'from' => $start, 'to' => $end, 'partial' => $partial];
        }

        $ranks = [self::MEASURED => 0, self::INTERPOLATED => 1, self::ESTIMATED => 2];
        $source = $ranks[$startSource] >= $ranks[$endSource] ? $startSource : $endSource;

        return [
            'kwh' => (int) round(max(0, $endValue - $startValue) * max(1, $meter->factor)),
            'source' => $source,
            'from' => $start,
            'to' => $end,
            'partial' => $partial,
        ];
    }

    /**
     * Zählerstand zu Beginn des Tages $date.
     *
     * @param  Collection<int, Reading>  $readings  nach Datum und Wert sortiert
     * @return array{0: ?float, 1: ?string}
     */
    public function valueAt(Collection $readings, CarbonImmutable $date): array
    {
        $day = $date->toDateString();

        $same = $readings->first(fn (Reading $r) => $r->read_on->toDateString() === $day);
        if ($same) {
            return [(float) $same->value, self::MEASURED];
        }

        $before = $readings->filter(fn (Reading $r) => $r->read_on->toDateString() < $day)->values();
        $after = $readings->first(fn (Reading $r) => $r->read_on->toDateString() > $day);
        $prev = $before->last();

        if ($prev && $after) {
            $span = $this->days($prev->read_on, $after->read_on);
            $part = $this->days($prev->read_on, $date);

            return [$prev->value + ($after->value - $prev->value) * $part / $span, self::INTERPOLATED];
        }

        if ($prev) {
            // Hochrechnung mit dem Tagesverbrauch zwischen den letzten beiden Ablesungen.
            $earlier = $before->filter(fn (Reading $r) => $r->read_on->lessThan($prev->read_on))->last();
            if (! $earlier) {
                return [null, null];
            }

            $daily = ($prev->value - $earlier->value) / max(1, $this->days($earlier->read_on, $prev->read_on));

            return [$prev->value + $daily * $this->days($prev->read_on, $date), self::ESTIMATED];
        }

        return [null, null];
    }

    /** Ganze Kalendertage zwischen zwei Daten (unabhängig von Sommer-/Winterzeit). */
    private function days(CarbonInterface $from, CarbonInterface $to): int
    {
        return intdiv(strtotime($to->toDateString().' UTC') - strtotime($from->toDateString().' UTC'), 86400);
    }

    /** @return Collection<int, Reading> */
    private function load(Meter $meter, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return $this->readings[$meter->id] = Reading::query()
            ->valid()
            ->where('meter_id', $meter->id)
            ->whereBetween('read_on', [$start->subYear()->toDateString(), $end->addYear()->toDateString()])
            ->orderBy('read_on')
            ->orderBy('value')
            ->get(['id', 'meter_id', 'read_on', 'value']);
    }

    public static function sourceLabel(string $source): string
    {
        return match ($source) {
            self::MEASURED => __('gemessen'),
            self::INTERPOLATED => __('interpoliert'),
            self::ESTIMATED => __('hochgerechnet'),
            self::SETTLED => __('abgerechnet'),
            self::NOT_INSTALLED => __('nicht eingebaut'),
            default => __('keine Daten'),
        };
    }
}

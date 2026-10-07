<?php

namespace App\Services\Analyzer;

use App\Models\AnalyzerDevice;
use App\Models\AnalyzerReading;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Verbrauch je Platz als Differenz der kWh-Stände aufeinanderfolgender Ablesungen (sortiert nach Zeit).
 *
 * - Plan-Ablesungen (00:00 Ortszeit) bilden die Tagesgrenzen, Start- und Handablesungen sind zusätzliche Punkte.
 * - Ein Abschnitt über mehrere Tage/Monate wird zeitanteilig verteilt ("partial").
 * - Ein Rückgang des Standes oder eine geänderte Adresse/Modell ist eine Unterbrechung (Zählertausch, Umklemmen,
 *   anderer Wandler): dieser Abschnitt zählt nicht zur Summe und wird unter "gaps" ausgegeben.
 */
class AnalyzerConsumption
{
    /**
     * @param  CarbonImmutable  $from  erster Tag (Ortszeit, einschließlich)
     * @param  CarbonImmutable  $to  letzter Tag (Ortszeit, einschließlich)
     * @return array{rows: list<array<string, mixed>>, totals: list<array<string, mixed>>, gaps: list<array<string, mixed>>}
     */
    public function calculate(AnalyzerDevice $device, ?int $slot, CarbonImmutable $from, CarbonImmutable $to, string $group = 'day'): array
    {
        $tz = config('energie.analyzer_timezone');
        $start = CarbonImmutable::parse($from->toDateString(), $tz)->startOfDay();
        $end = CarbonImmutable::parse($to->toDateString(), $tz)->addDay()->startOfDay();

        $slots = $slot ? [$slot] : AnalyzerReading::query()->where('device_id', $device->id)->distinct()->orderBy('slot')->pluck('slot')->all();
        $names = $device->slots()->pluck('name', 'slot');

        $rows = [];
        $totals = [];
        $gaps = [];

        foreach ($slots as $s) {
            $buckets = [];
            $points = $this->points($device, $s, $start, $end);

            foreach ($points->sliding(2) as $pair) {
                [$a, $b] = $pair->values()->all();
                $reason = $this->discontinuity($a, $b);

                if ($reason) {
                    $gaps[] = [
                        'slot' => $s,
                        'from' => $a->localTs()->toIso8601String(),
                        'to' => $b->localTs()->toIso8601String(),
                        'from_kwh' => (float) $a->kwh,
                        'to_kwh' => (float) $b->kwh,
                        'from_addr' => $a->addr,
                        'to_addr' => $b->addr,
                        'reason' => $reason,
                    ];

                    continue;
                }

                $this->distribute($buckets, $a, $b, $start, $end, $group, $tz);
            }

            ksort($buckets);
            foreach ($buckets as $period => $bucket) {
                $rows[] = ['slot' => $s, 'name' => $names[$s] ?? null, 'period' => $period, 'kwh' => round($bucket['kwh'], 2), 'partial' => $bucket['partial']];
            }

            $totals[] = ['slot' => $s, 'name' => $names[$s] ?? null, 'kwh' => round(array_sum(array_column($buckets, 'kwh')), 2)];
        }

        return ['rows' => $rows, 'totals' => $totals, 'gaps' => $gaps];
    }

    /**
     * Ablesungen mit Zeit im Zeitraum plus die letzte davor und die erste danach (für angeschnittene Abschnitte).
     *
     * @return Collection<int, AnalyzerReading>
     */
    private function points(AnalyzerDevice $device, int $slot, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        $utc = fn (CarbonImmutable $d) => $d->setTimezone('UTC')->format('Y-m-d H:i:s');
        $base = fn () => AnalyzerReading::query()->where('device_id', $device->id)->where('slot', $slot)->whereNotNull('ts');

        $inside = $base()->where('ts', '>=', $utc($start))->where('ts', '<=', $utc($end))->orderBy('ts')->orderBy('boot')->orderBy('up')->get();
        $before = $base()->where('ts', '<', $utc($start))->orderByDesc('ts')->orderByDesc('boot')->orderByDesc('up')->first();
        $after = $base()->where('ts', '>', $utc($end))->orderBy('ts')->orderBy('boot')->orderBy('up')->first();

        return collect([$before])->merge($inside)->push($after)->filter()->values();
    }

    private function discontinuity(AnalyzerReading $a, AnalyzerReading $b): ?string
    {
        return match (true) {
            bccomp((string) $b->kwh, (string) $a->kwh, 2) < 0 => 'drop',
            $a->addr !== $b->addr => 'addr',
            $a->model !== null && $b->model !== null && $a->model !== $b->model => 'model',
            default => null,
        };
    }

    /** Verbrauch zwischen zwei Ablesungen zeitanteilig auf Tage bzw. Monate im Zeitraum verteilen. */
    private function distribute(array &$buckets, AnalyzerReading $a, AnalyzerReading $b, CarbonImmutable $start, CarbonImmutable $end, string $group, string $tz): void
    {
        $from = $a->ts->setTimezone($tz);
        $to = $b->ts->setTimezone($tz);
        $seconds = $to->getTimestamp() - $from->getTimestamp();
        $delta = (float) bcsub((string) $b->kwh, (string) $a->kwh, 2);

        if ($seconds <= 0 || $to->lessThanOrEqualTo($start) || $from->greaterThanOrEqualTo($end)) {
            return;
        }

        $cursor = $from;
        while ($cursor->lessThan($to)) {
            $bucketStart = $group === 'month' ? $cursor->startOfMonth() : $cursor->startOfDay();
            $bucketEnd = $group === 'month' ? $bucketStart->addMonthNoOverflow() : $bucketStart->addDay();
            $segmentEnd = $bucketEnd->lessThan($to) ? $bucketEnd : $to;

            $overlapStart = $cursor->greaterThan($start) ? $cursor : $start;
            $overlapEnd = $segmentEnd->lessThan($end) ? $segmentEnd : $end;

            if ($overlapEnd->greaterThan($overlapStart)) {
                $key = $bucketStart->format($group === 'month' ? 'Y-m' : 'Y-m-d');
                $share = $delta * ($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / $seconds;
                $buckets[$key]['kwh'] = ($buckets[$key]['kwh'] ?? 0) + $share;
                // Anteilig = der Abschnitt reicht über die Grenze des Tages/Monats hinaus.
                $buckets[$key]['partial'] = ($buckets[$key]['partial'] ?? false) || $from->lessThan($bucketStart) || $to->greaterThan($bucketEnd);
            }

            $cursor = $segmentEnd;
        }
    }
}

<?php

namespace App\Services;

use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Kennzahlen für Dashboard und Berichte.
 */
class Statistics
{
    /**
     * Abgerechneter Verbrauch und Umsatz je Monat.
     *
     * @return Collection<string, array{kwh: int, net: float}>
     */
    public function monthlyTotals(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $settlements = Settlement::query()->effective()
            ->whereBetween('period', [$from->toDateString(), $to->toDateString()])
            ->get(['period', 'billed_kwh', 'net_amount']);

        return $this->months($from, $to)->mapWithKeys(function (CarbonImmutable $month) use ($settlements) {
            $rows = $settlements->filter(fn ($s) => $s->period->isSameMonth($month));

            return [$month->format('Y-m') => [
                'kwh' => (int) $rows->sum('billed_kwh'),
                'net' => round((float) $rows->sum('net_amount'), 2),
            ]];
        });
    }

    /**
     * Vergleich Hauptzähler (Rechnung des Versorgers) mit der Summe der abgerechneten Unterzähler.
     *
     * @return Collection<int, array{meter: Meter, months: Collection<string, array{supplier: float, submeters: int, difference: float, percent: ?float}>}>
     */
    public function mainMeterComparison(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $prices = ElectricityPrice::query()
            ->whereBetween('month', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy('meter_id');

        $settlements = Settlement::query()->effective()
            ->whereBetween('period', [$from->toDateString(), $to->toDateString()])
            ->get(['main_meter_id', 'period', 'billed_kwh'])
            ->groupBy('main_meter_id');

        return Meter::query()->main()->orderBy('number')->get()->map(function (Meter $meter) use ($from, $to, $prices, $settlements) {
            $months = $this->months($from, $to)->mapWithKeys(function (CarbonImmutable $month) use ($meter, $prices, $settlements) {
                $supplier = (float) ($prices->get($meter->id)?->first(fn ($p) => $p->month->isSameMonth($month))?->consumption_kwh ?? 0);
                $sub = (int) $settlements->get($meter->id, collect())->filter(fn ($s) => $s->period->isSameMonth($month))->sum('billed_kwh');
                $difference = $supplier - $sub;

                return [$month->format('Y-m') => [
                    'supplier' => $supplier,
                    'submeters' => $sub,
                    'difference' => $difference,
                    'percent' => $supplier > 0 ? round($difference / $supplier * 100, 1) : null,
                ]];
            });

            return ['meter' => $meter, 'months' => $months];
        });
    }

    /**
     * Aktive Zähler ohne freigegebene Ablesung im Monat (Ablesestatus).
     *
     * @return Collection<int, Meter>
     */
    public function metersWithoutReading(CarbonInterface $month): Collection
    {
        $start = CarbonImmutable::parse($month)->startOfMonth();

        $read = Reading::query()
            ->where('source', '!=', 'system')
            ->whereBetween('read_on', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->pluck('meter_id')
            ->unique();

        return Meter::query()->active()
            ->whereNotIn('id', $read)
            ->with('tenant')
            ->orderBy('number')
            ->get();
    }

    /** @return Collection<int, CarbonImmutable> */
    private function months(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $months = collect();
        $month = CarbonImmutable::parse($from)->startOfMonth();

        while ($month->lessThanOrEqualTo($to)) {
            $months->push($month);
            $month = $month->addMonthNoOverflow();
        }

        return $months;
    }
}

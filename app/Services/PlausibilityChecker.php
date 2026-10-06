<?php

namespace App\Services;

use App\Enums\ReadingStatus;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use Carbon\CarbonInterface;

/**
 * Prüft neue Ablesungen auf Plausibilität. Auffällige Werte werden nicht abgelehnt,
 * sondern als "zu prüfen" markiert und erst nach Freigabe für Abrechnungen verwendet.
 */
class PlausibilityChecker
{
    /**
     * @return array{status: ReadingStatus, note: ?string}
     */
    public function check(Meter $meter, int $value, CarbonInterface $date, ?int $ignoreReadingId = null): array
    {
        $readings = $meter->readings()->valid()
            ->when($ignoreReadingId, fn ($q) => $q->whereKeyNot($ignoreReadingId));

        $previous = (clone $readings)->whereDate('read_on', '<=', $date)->orderByDesc('read_on')->orderByDesc('value')->first();
        $next = (clone $readings)->whereDate('read_on', '>', $date)->orderBy('read_on')->first();

        if ($previous && $value < $previous->value) {
            return $this->pending(__('Zählerstand kleiner als die vorherige Ablesung (:value am :date).', [
                'value' => $meter->formatValue($previous->value, true), 'date' => $previous->read_on->format('d.m.Y'),
            ]));
        }

        if ($next && $value > $next->value) {
            return $this->pending(__('Zählerstand größer als die spätere Ablesung (:value am :date).', [
                'value' => $meter->formatValue($next->value, true), 'date' => $next->read_on->format('d.m.Y'),
            ]));
        }

        if ($previous && ($days = (int) $previous->read_on->diffInDays($date)) > 0) {
            $daily = ($value - $previous->value) / $days;
            $average = $this->averageDailyConsumption($meter, $previous->read_on, $ignoreReadingId);
            $multiplier = (float) Setting::get('plausibility_multiplier');

            if ($average > 0 && $daily > $average * $multiplier) {
                // Wasser: Liter -> m³ (3 Nachkommastellen), Strom: kWh (1 Nachkommastelle).
                $scale = $meter->medium->scale();
                $decimals = $meter->isWater() ? 3 : 1;

                return $this->pending(__('Ungewöhnlich hoher Verbrauch: :daily :unit/Tag (Durchschnitt :average :unit/Tag).', [
                    'daily' => number_format($daily / $scale, $decimals, ',', '.'),
                    'average' => number_format($average / $scale, $decimals, ',', '.'),
                    'unit' => $meter->unit(),
                ]));
            }
        }

        return ['status' => ReadingStatus::Approved, 'note' => null];
    }

    /** Durchschnittlicher Tagesverbrauch der letzten 12 Monate vor dem Stichtag. */
    public function averageDailyConsumption(Meter $meter, CarbonInterface $until, ?int $ignoreReadingId = null): float
    {
        $readings = $meter->readings()->valid()
            ->when($ignoreReadingId, fn ($q) => $q->whereKeyNot($ignoreReadingId))
            ->whereBetween('read_on', [$until->copy()->subYear()->toDateString(), $until->toDateString()])
            ->orderBy('read_on')
            ->get(['value', 'read_on']);

        /** @var Reading|null $first */
        $first = $readings->first();
        $last = $readings->last();

        if (! $first || $first === $last || ($days = (int) $first->read_on->diffInDays($last->read_on)) === 0) {
            return 0.0;
        }

        return ($last->value - $first->value) / $days;
    }

    private function pending(string $note): array
    {
        return ['status' => ReadingStatus::Pending, 'note' => $note];
    }
}

<?php

namespace App\Services\Analyzer;

use App\Enums\ReadingSource;
use App\Models\AnalyzerDevice;
use App\Models\AnalyzerReading;
use App\Models\AnalyzerSlot;
use App\Models\Reading;
use App\Services\ReadingService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Übernimmt Analysator-Werte als Zählerstände des zugeordneten Zählers (Messpunkt):
 * höchstens eine Ablesung je Tag, und zwar die früheste (Plan-Ablesung um 00:00 Ortszeit = Stand zu Tagesbeginn).
 * Damit gehen die Messpunkte wie alle anderen Zähler in den Monatsverbrauch "vom 1. bis zum 1." ein.
 */
class MeterReadingSync
{
    public function __construct(private ReadingService $readings) {}

    /**
     * @param  list<array{slot: int, ts: ?string, kwh: string|float}>  $rows  Neue Rohdaten (ts in UTC)
     */
    public function sync(AnalyzerDevice $device, array $rows): int
    {
        $slots = AnalyzerSlot::query()
            ->where('device_id', $device->id)
            ->whereNotNull('meter_id')
            ->with('meter')
            ->get()
            ->keyBy('slot');

        if ($slots->isEmpty()) {
            return 0;
        }

        $timezone = config('energie.analyzer_timezone');
        $daily = [];

        foreach ($rows as $row) {
            $slot = $slots->get($row['slot']);

            if (! $slot?->meter?->is_active || $row['ts'] === null) {
                continue;
            }

            $ts = CarbonImmutable::parse($row['ts'], 'UTC');
            if ($slot->meter_since && $ts->lessThan($slot->meter_since)) {
                continue;
            }

            $date = $ts->setTimezone($timezone)->toDateString();
            $value = (int) floor((float) $row['kwh']);
            $key = $slot->meter_id.'|'.$date;

            if (! isset($daily[$key]) || $value < $daily[$key]['value']) {
                $daily[$key] = ['slot' => $slot, 'date' => $date, 'value' => $value];
            }
        }

        $count = 0;
        foreach ($daily as $item) {
            $count += $this->store($device, $item['slot'], $item['date'], $item['value']) ? 1 : 0;
        }

        return $count;
    }

    /** Vorhandene Rohdaten eines Platzes ab einem Zeitpunkt übernehmen (nach neuer Zuordnung). */
    public function backfill(AnalyzerSlot $slot, CarbonInterface $since): int
    {
        $rows = AnalyzerReading::query()
            ->where('device_id', $slot->device_id)
            ->where('slot', $slot->slot)
            ->whereNotNull('ts')
            ->where('ts', '>=', CarbonImmutable::parse($since)->setTimezone('UTC')->format('Y-m-d H:i:s'))
            ->orderBy('ts')
            ->get()
            ->map(fn (AnalyzerReading $r) => ['slot' => $r->slot, 'ts' => $r->getRawOriginal('ts'), 'kwh' => $r->kwh])
            ->all();

        return $this->sync($slot->device, $rows);
    }

    private function store(AnalyzerDevice $device, AnalyzerSlot $slot, string $date, int $value): bool
    {
        $existing = Reading::query()
            ->where('meter_id', $slot->meter_id)
            ->whereDate('read_on', $date)
            ->where('source', ReadingSource::Analyzer)
            ->first();

        if ($existing) {
            // Ein früherer Wert desselben Tages ersetzt den späteren.
            if ($value < $existing->value) {
                $existing->update(['value' => $value]);

                return true;
            }

            return false;
        }

        $this->readings->record(
            $slot->meter,
            $value,
            CarbonImmutable::parse($date),
            ReadingSource::Analyzer,
            $device->name.' / '.__('Platz').' '.$slot->slot,
        );

        return true;
    }
}

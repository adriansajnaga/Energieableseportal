<?php

namespace App\Services\Analyzer;

use App\Casts\UtcDateTime;
use App\Models\AnalyzerDevice;
use App\Models\AnalyzerReading;
use App\Models\AnalyzerSlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Nimmt ein Paket des Analysators entgegen (Firmware-Format, siehe README).
 *
 * Wichtigste Regel: Fehlerhafte einzelne Ablesungen/Zähler werden übersprungen und protokolliert, nie das ganze Paket
 * abgelehnt – eine 4xx/5xx-Antwort würde die Warteschlange im Gerät dauerhaft blockieren.
 */
class AnalyzerIngestor
{
    // Gültige Unix-Zeiten (2000-01-01 bis 2100-01-01); alles andere gilt als "Uhr nicht gestellt".
    private const MIN_TS = 946684800;

    private const MAX_TS = 4102444800;

    private const MAX_UINT = 4294967295;

    public function __construct(private MeterReadingSync $sync) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{saved: int, duplicates: int, skipped: int}
     */
    public function ingest(AnalyzerDevice $device, array $payload, CarbonImmutable $receivedAt): array
    {
        $name = $payload['device'] ?? null;
        if ($name !== $device->name) {
            Log::warning('Analysator: Name im Paket passt nicht zum Token, Daten werden unter dem Gerät des Tokens gespeichert.', [
                'token_device' => $device->name,
                'payload_device' => $name,
            ]);
        }

        $now = is_array($payload['now'] ?? null) ? $payload['now'] : [];
        $nowTs = $this->timestamp($now['ts'] ?? null);
        $nowBoot = $this->uint($now['boot'] ?? null);
        $nowUp = $this->uint($now['up'] ?? null);
        $names = $this->names($payload['names'] ?? null);
        $received = UtcDateTime::format($receivedAt);

        $rows = [];
        $skipped = 0;

        foreach ($payload['readings'] as $reading) {
            $meters = is_array($reading) && is_array($reading['meters'] ?? null) ? $reading['meters'] : null;
            $boot = is_array($reading) ? $this->uint($reading['boot'] ?? null) : null;
            $up = is_array($reading) ? $this->uint($reading['up'] ?? null) : null;
            $reason = is_array($reading) ? ($reading['reason'] ?? null) : null;
            $rawTs = is_array($reading) ? ($reading['ts'] ?? null) : null;

            if ($meters === null || $boot === null || $up === null
                || ! in_array($reason, AnalyzerReading::REASONS, true)
                || ($rawTs !== null && ! is_int($rawTs))) {
                $skipped += $meters ? count($meters) : 1;
                Log::warning('Analysator: ungültige Ablesung übersprungen.', ['device' => $device->name, 'reading' => $reading]);

                continue;
            }

            [$ts, $reconstructed, $review] = $this->time($this->timestamp($rawTs), $boot, $up, $nowTs, $nowBoot, $nowUp, $receivedAt);

            foreach ($meters as $meter) {
                $values = $this->meter($meter);

                if (! $values) {
                    $skipped++;
                    Log::warning('Analysator: ungültiger Zählerwert übersprungen.', ['device' => $device->name, 'boot' => $boot, 'up' => $up, 'meter' => $meter]);

                    continue;
                }

                $rows[] = [
                    'device_id' => $device->id,
                    'slot' => $values['slot'],
                    'addr' => $values['addr'],
                    'model' => $values['model'],
                    'meter_name' => $names[$values['slot']] ?? null,
                    'boot' => $boot,
                    'up' => $up,
                    'ts' => $ts,
                    'ts_reconstructed' => $reconstructed,
                    'needs_review' => $review,
                    'reason' => $reason,
                    'kwh' => $values['kwh'],
                    'received_at' => $received,
                ];
            }
        }

        $new = DB::transaction(function () use ($device, $rows, $names, $payload, $nowBoot, $received) {
            $new = $this->withoutDuplicates($device, $rows);

            foreach (array_chunk($new, 200) as $chunk) {
                AnalyzerReading::query()->insertOrIgnore($chunk);
            }

            $this->updateSlots($device, $rows, $names);

            $device->forceFill([
                'last_seen_at' => $received,
                'fw' => is_string($payload['fw'] ?? null) ? mb_substr($payload['fw'], 0, 20) : $device->fw,
                'last_boot' => $nowBoot ?? $device->last_boot,
            ])->save();

            return $new;
        });

        // Tageswerte für zugeordnete Zähler übernehmen. Fehler hier dürfen die Antwort nicht verhindern.
        try {
            $this->sync->sync($device, $new);
        } catch (Throwable $e) {
            Log::error('Analysator: Übernahme in die Zählerstände fehlgeschlagen.', ['device' => $device->name, 'error' => $e->getMessage()]);
        }

        return ['saved' => count($new), 'duplicates' => count($rows) - count($new), 'skipped' => $skipped];
    }

    /**
     * Zeit der Ablesung in UTC. Ohne Uhrzeit wird sie aus Startnummer und Laufzeit rekonstruiert,
     * wenn die Ablesung aus dem aktuellen Start stammt; sonst bleibt sie leer und muss geprüft werden.
     *
     * @return array{0: ?string, 1: bool, 2: bool}
     */
    private function time(?int $ts, int $boot, int $up, ?int $nowTs, ?int $nowBoot, ?int $nowUp, CarbonImmutable $receivedAt): array
    {
        if ($ts !== null) {
            return [UtcDateTime::format($ts), false, false];
        }

        if ($nowBoot !== null && $boot === $nowBoot && $nowUp !== null) {
            $reconstructed = ($nowTs ?? $receivedAt->getTimestamp()) - ($nowUp - $up);

            if ($reconstructed >= self::MIN_TS && $reconstructed <= self::MAX_TS) {
                return [UtcDateTime::format($reconstructed), true, false];
            }
        }

        return [null, false, true];
    }

    /** @return array{slot: int, addr: int, model: ?string, kwh: string}|null */
    private function meter(mixed $meter): ?array
    {
        if (! is_array($meter)) {
            return null;
        }

        $slot = $meter['slot'] ?? null;
        $addr = $meter['addr'] ?? null;
        $kwh = $meter['kwh'] ?? null;
        $model = $meter['model'] ?? null;

        if (! is_int($slot) || $slot < 1 || $slot > 6 || ! is_int($addr) || $addr < 1 || $addr > 245) {
            return null;
        }

        if (! (is_int($kwh) || is_float($kwh)) || ! is_finite((float) $kwh) || $kwh < 0 || $kwh >= 1e12) {
            return null;
        }

        // Die Leistung (p_kw) ist nur ein Richtwert und wird bewusst nicht gespeichert.
        return [
            'slot' => $slot,
            'addr' => $addr,
            'model' => is_string($model) && $model !== '' ? mb_substr($model, 0, 20) : null,
            'kwh' => number_format((float) $kwh, 2, '.', ''),
        ];
    }

    /**
     * Bereits gespeicherte und im Paket doppelte Ablesungen (gleiches boot/up/slot) entfernen.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withoutDuplicates(AnalyzerDevice $device, array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $key = fn ($r) => $r['boot'].'-'.$r['up'].'-'.$r['slot'];

        $existing = AnalyzerReading::query()
            ->where('device_id', $device->id)
            ->whereIn('boot', array_unique(array_column($rows, 'boot')))
            ->whereIn('up', array_unique(array_column($rows, 'up')))
            ->get(['boot', 'up', 'slot'])
            ->mapWithKeys(fn ($r) => [$key($r->getAttributes()) => true])
            ->all();

        $new = [];
        foreach ($rows as $row) {
            if (! isset($existing[$key($row)])) {
                $existing[$key($row)] = true;
                $new[] = $row;
            }
        }

        return $new;
    }

    /** Aktuelle Namen aus "names" und letzte Adresse/Modell je Platz übernehmen. */
    private function updateSlots(AnalyzerDevice $device, array $rows, array $names): void
    {
        $latest = [];
        foreach ($rows as $row) {
            $latest[$row['slot']] = $row;
        }

        foreach (array_unique([...array_keys($names), ...array_keys($latest)]) as $slot) {
            $values = [];

            if (array_key_exists($slot, $names)) {
                $values['name'] = $names[$slot];
            }

            if (isset($latest[$slot])) {
                $values['addr'] = $latest[$slot]['addr'];
                $values['model'] = $latest[$slot]['model'] ?? AnalyzerSlot::query()
                    ->where('device_id', $device->id)->where('slot', $slot)->value('model');
            }

            AnalyzerSlot::query()->updateOrCreate(['device_id' => $device->id, 'slot' => $slot], $values);
        }
    }

    /** @return array<int, string> */
    private function names(mixed $names): array
    {
        if (! is_array($names)) {
            return [];
        }

        $result = [];
        foreach ($names as $slot => $name) {
            if (is_numeric($slot) && (int) $slot >= 1 && (int) $slot <= 6 && is_string($name)) {
                $result[(int) $slot] = mb_substr(trim($name), 0, 255);
            }
        }

        return $result;
    }

    private function uint(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_UINT ? $value : null;
    }

    private function timestamp(mixed $value): ?int
    {
        return is_int($value) && $value >= self::MIN_TS && $value <= self::MAX_TS ? $value : null;
    }
}

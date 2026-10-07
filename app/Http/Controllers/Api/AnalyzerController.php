<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AnalyzerDevice;
use App\Models\AnalyzerReading;
use App\Models\AnalyzerSlot;
use App\Services\Analyzer\AnalyzerConsumption;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lesende Endpunkte für angemeldete Benutzer (Sitzung). Zeiten in der Antwort: UTC ("ts") und Ortszeit ("ts_local").
 */
class AnalyzerController extends Controller
{
    public function devices(): JsonResponse
    {
        $devices = AnalyzerDevice::query()->withCount('readings')->with('slots.meter')->orderBy('name')->get();

        return response()->json(['data' => $devices->map(fn (AnalyzerDevice $d) => [
            'id' => $d->id,
            'name' => $d->name,
            'fw' => $d->fw,
            'last_boot' => $d->last_boot,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'readings_count' => $d->readings_count,
            'slots' => $d->slots->map(function (AnalyzerSlot $slot) {
                $last = $slot->latestReading();

                return [
                    'slot' => $slot->slot,
                    'name' => $slot->name,
                    'addr' => $slot->addr,
                    'model' => $slot->model,
                    'meter' => $slot->meter ? ['id' => $slot->meter->id, 'number' => $slot->meter->number] : null,
                    'last_kwh' => $last ? (float) $last->kwh : null,
                    'last_ts' => $last?->ts?->toIso8601String(),
                ];
            })->values(),
        ])->values()]);
    }

    public function readings(Request $request, AnalyzerDevice $device): JsonResponse
    {
        $filters = $this->filters($request);

        $readings = $this->query($device, $filters)
            ->when($filters['reason'] ?? null, fn (Builder $q, $reason) => $q->where('reason', $reason))
            ->orderByRaw('ts is null')
            ->orderByDesc('ts')
            ->orderByDesc('id')
            ->paginate(min(500, max(1, (int) $request->query('per_page', 100))))
            ->withQueryString()
            ->through(fn (AnalyzerReading $r) => $this->reading($r));

        return response()->json($readings);
    }

    public function consumption(Request $request, AnalyzerDevice $device, AnalyzerConsumption $consumption): JsonResponse
    {
        $filters = $this->filters($request, withGroup: true);
        $from = $filters['from'] ?? CarbonImmutable::now(config('energie.analyzer_timezone'))->startOfMonth();
        $to = $filters['to'] ?? CarbonImmutable::now(config('energie.analyzer_timezone'));
        $group = $filters['group'] ?? 'day';

        return response()->json([
            'device' => $device->name,
            'group' => $group,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'timezone' => config('energie.analyzer_timezone'),
            ...$consumption->calculate($device, $filters['slot'] ?? null, $from, $to, $group),
        ]);
    }

    /** CSV für Excel: Semikolon, Dezimalkomma, UTF-8 mit BOM, Zeit in Ortszeit. */
    public function export(Request $request, AnalyzerDevice $device): StreamedResponse
    {
        $filters = $this->filters($request);
        $query = $this->query($device, $filters)->orderBy('slot')->orderByRaw('ts is null')->orderBy('ts')->orderBy('boot')->orderBy('up');
        $name = 'Analysator_'.str($device->name)->slug().'_'.($filters['from'] ?? null)?->format('Y-m-d').'_'.($filters['to'] ?? null)?->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Platz', 'Name', 'Adresse', 'Modell', 'Zeit (Ortszeit)', 'Zeit rekonstruiert', 'Zu prüfen', 'Grund', 'kWh', 'Start-Nr.', 'Laufzeit (s)', 'Empfangen (Ortszeit)'], ';');

            $query->chunk(1000, function ($readings) use ($out) {
                foreach ($readings as $r) {
                    fputcsv($out, [
                        $r->slot,
                        $r->meter_name,
                        $r->addr,
                        $r->model,
                        $r->localTs()?->format('d.m.Y H:i:s'),
                        $r->ts_reconstructed ? 'ja' : 'nein',
                        $r->needs_review ? 'ja' : 'nein',
                        $r->reason,
                        number_format((float) $r->kwh, 2, ',', ''),
                        $r->boot,
                        $r->up,
                        $r->received_at->setTimezone(config('energie.analyzer_timezone'))->format('d.m.Y H:i:s'),
                    ], ';');
                }
            });

            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{slot?: int, from?: CarbonImmutable, to?: CarbonImmutable, reason?: string, group?: string} */
    private function filters(Request $request, bool $withGroup = false): array
    {
        $data = $request->validate([
            'slot' => ['nullable', 'integer', 'between:1,6'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'reason' => ['nullable', Rule::in(AnalyzerReading::REASONS)],
            'group' => [$withGroup ? 'nullable' : 'prohibited', Rule::in(['day', 'month'])],
        ]);

        $tz = config('energie.analyzer_timezone');

        return array_filter([
            'slot' => isset($data['slot']) ? (int) $data['slot'] : null,
            'from' => isset($data['from']) ? CarbonImmutable::parse($data['from'], $tz)->startOfDay() : null,
            'to' => isset($data['to']) ? CarbonImmutable::parse($data['to'], $tz)->startOfDay() : null,
            'reason' => $data['reason'] ?? null,
            'group' => $data['group'] ?? null,
        ], fn ($v) => $v !== null);
    }

    private function query(AnalyzerDevice $device, array $filters): Builder
    {
        $utc = fn (CarbonImmutable $d) => $d->setTimezone('UTC')->format('Y-m-d H:i:s');

        return AnalyzerReading::query()
            ->where('device_id', $device->id)
            ->when($filters['slot'] ?? null, fn (Builder $q, $slot) => $q->where('slot', $slot))
            ->when($filters['from'] ?? null, fn (Builder $q, $from) => $q->where('ts', '>=', $utc($from)))
            ->when($filters['to'] ?? null, fn (Builder $q, $to) => $q->where('ts', '<', $utc($to->addDay())));
    }

    private function reading(AnalyzerReading $r): array
    {
        return [
            'id' => $r->id,
            'slot' => $r->slot,
            'addr' => $r->addr,
            'model' => $r->model,
            'meter_name' => $r->meter_name,
            'boot' => $r->boot,
            'up' => $r->up,
            'ts' => $r->ts?->toIso8601String(),
            'ts_local' => $r->localTs()?->toIso8601String(),
            'ts_reconstructed' => $r->ts_reconstructed,
            'needs_review' => $r->needs_review,
            'reason' => $r->reason,
            'kwh' => (float) $r->kwh,
            'received_at' => $r->received_at->toIso8601String(),
        ];
    }
}

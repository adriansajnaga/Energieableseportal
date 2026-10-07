<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Analyzer\AnalyzerIngestor;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/analyzer/readings – Pakete des Analysators (max. 20 Ablesungen).
 * 2xx bestätigt das Paket; jede andere Antwort lässt das Gerät dasselbe Paket nach 15 s erneut senden.
 * 422 deshalb nur, wenn der Body kein JSON ist oder "readings" fehlt.
 */
class AnalyzerIngestController extends Controller
{
    public function __invoke(Request $request, AnalyzerIngestor $ingestor): JsonResponse
    {
        $receivedAt = CarbonImmutable::now('UTC');
        $payload = json_decode($request->getContent(), true);

        if (! is_array($payload) || ! is_array($payload['readings'] ?? null)) {
            return response()->json(['ok' => false, 'error' => 'invalid payload: JSON with "readings" expected'], 422);
        }

        $result = $ingestor->ingest($request->attributes->get('analyzer_device'), $payload, $receivedAt);

        return response()->json(['ok' => true, ...$result]);
    }
}

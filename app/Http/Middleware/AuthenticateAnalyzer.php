<?php

namespace App\Http\Middleware;

use App\Models\AnalyzerDevice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Anmeldung des Analysators per "Authorization: Bearer <token>" (in der Datenbank nur der SHA-256-Hash). */
class AuthenticateAnalyzer
{
    public function handle(Request $request, Closure $next): Response
    {
        $device = AnalyzerDevice::findByToken($request->bearerToken());

        if (! $device) {
            return response()->json(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $request->attributes->set('analyzer_device', $device);

        return $next($request);
    }
}

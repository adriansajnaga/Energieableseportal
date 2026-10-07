<?php

namespace App\Http\Controllers;

use App\Models\SitePlan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Rzut obiektu anzeigen (nur angemeldet, inline für Bild bzw. PDF-Ansicht im Browser). */
class SitePlanController extends Controller
{
    public function __invoke(SitePlan $plan): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($plan->path), 404);

        return Storage::disk('local')->response($plan->path, $plan->original_name, [
            'Content-Type' => $plan->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }
}

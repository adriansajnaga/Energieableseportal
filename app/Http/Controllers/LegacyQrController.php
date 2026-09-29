<?php

namespace App\Http\Controllers;

use App\Models\Meter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Leitet alte QR-Codes (reading.php?reader=...&meter=<md5>) auf die neue Ableseseite um,
 * damit die bereits aufgeklebten Etiketten weiter funktionieren. Über
 * ENERGIE_LEGACY_QR=false abschaltbar, sobald neue Etiketten angebracht sind.
 */
class LegacyQrController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        abort_unless(config('energie.legacy_qr'), 404);

        $hash = (string) $request->query('meter');
        abort_unless(preg_match('/^[a-f0-9]{32}$/', $hash) === 1, 404);

        $meter = Meter::query()->where('legacy_hash', $hash)->where('is_active', true)->firstOrFail();

        return redirect()->route('public.reading', $meter->qr_token);
    }
}

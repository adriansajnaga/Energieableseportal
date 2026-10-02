<?php

namespace App\Http\Controllers;

use App\Models\Meter;
use App\Support\QrLabelImage;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * QR-Etiketten als Bild (9 × 13 cm) – einzeln oder alle aktiven Zähler als ZIP.
 */
class LabelController extends Controller
{
    public function __construct(private QrLabelImage $labels) {}

    public function image(Meter $meter, string $format): Response
    {
        Gate::authorize('manage');
        abort_unless(QrLabelImage::available(), 503, __('Für Bild-Etiketten wird die PHP-Erweiterung GD benötigt. Bitte beim Hoster aktivieren lassen.'));

        return response($this->labels->render($meter, $format), 200, [
            'Content-Type' => $format === 'jpg' ? 'image/jpeg' : 'image/png',
            'Content-Disposition' => 'attachment; filename="'.$this->labels->filename($meter, $format).'"',
        ]);
    }

    public function zip(string $format): BinaryFileResponse
    {
        Gate::authorize('manage');
        abort_unless(QrLabelImage::available(), 503, __('Für Bild-Etiketten wird die PHP-Erweiterung GD benötigt. Bitte beim Hoster aktivieren lassen.'));
        abort_unless(class_exists(ZipArchive::class), 503, __('Für den ZIP-Download wird die PHP-Erweiterung zip benötigt. Bitte beim Hoster aktivieren lassen.'));

        $file = tempnam(sys_get_temp_dir(), 'qr-etiketten');
        $zip = new ZipArchive;
        $zip->open($file, ZipArchive::OVERWRITE);

        Meter::query()->active()->orderBy('number')->each(function (Meter $meter) use ($zip, $format) {
            $zip->addFromString($this->labels->filename($meter, $format), $this->labels->render($meter, $format));
        });

        $zip->close();

        return response()->download($file, 'QR-Etiketten_'.now()->format('Y-m-d').'.zip', ['Content-Type' => 'application/zip'])
            ->deleteFileAfterSend();
    }
}

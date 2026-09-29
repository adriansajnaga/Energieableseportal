<?php

namespace App\Support;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode as Generator;
use chillerlan\QRCode\QROptions;

/**
 * QR-Codes als SVG (ohne GD-Erweiterung) für die Anzeige im Browser.
 * In PDFs erzeugt TCPDF die QR-Codes selbst.
 */
class QrCode
{
    public static function svg(string $data): string
    {
        $options = new QROptions([
            'eccLevel' => EccLevel::H,
            'outputBase64' => false,
            'svgAddXmlHeader' => false,
            'addQuietzone' => true,
            'quietzoneSize' => 2,
        ]);

        return (new Generator($options))->render($data);
    }
}

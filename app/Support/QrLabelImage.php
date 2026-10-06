<?php

namespace App\Support;

use App\Models\Meter;
use App\Models\Setting;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use GdImage;
use RuntimeException;

/**
 * QR-Etikett als Bild im Fotoformat 9 × 13 cm (Hochformat, 300 dpi) zum Ausdrucken
 * und Aufkleben am Zähler. Benötigt die PHP-Erweiterung GD mit FreeType.
 */
class QrLabelImage
{
    public const WIDTH = 1063;   // 9 cm bei 300 dpi

    public const HEIGHT = 1535;  // 13 cm bei 300 dpi

    public static function available(): bool
    {
        return function_exists('imagecreatetruecolor') && function_exists('imagettftext');
    }

    /** @return string Binärdaten als PNG oder JPEG */
    public function render(Meter $meter, string $format = 'png'): string
    {
        if (! self::available()) {
            throw new RuntimeException(__('Für Bild-Etiketten wird die PHP-Erweiterung GD benötigt. Bitte beim Hoster aktivieren lassen.'));
        }

        $img = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagesetthickness($img, 1);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 20, 20, 20);
        $grey = imagecolorallocate($img, 110, 110, 110);
        // Akzentfarbe je Medium (Strom grün, Kaltwasser blau, Warmwasser rot).
        $rgb = $meter->medium->labelRgb();
        $green = imagecolorallocate($img, ...$rgb['accent']);
        $greenLight = imagecolorallocate($img, ...$rgb['light']);
        imagefill($img, 0, 0, $white);

        $regular = resource_path('fonts/DejaVuSans.ttf');
        $bold = resource_path('fonts/DejaVuSans-Bold.ttf');
        $margin = 70;

        // Logo oben
        $y = 60;
        $logoFile = resource_path('pdf/logo.png');
        if (is_file($logoFile) && ($logo = @imagecreatefrompng($logoFile))) {
            $w = self::WIDTH - 2 * $margin - 120;
            $h = (int) round(imagesy($logo) * $w / imagesx($logo));
            imagecopyresampled($img, $logo, $margin, $y, 0, 0, $w, $h, imagesx($logo), imagesy($logo));
            imagedestroy($logo);
            $y += $h + 30;
        }
        imagefilledrectangle($img, $margin, $y, self::WIDTH - $margin, $y + 5, $green);
        $y += 75;

        $this->centered($img, $bold, 54, $black, 'Zählerstand melden', $y);
        $y += 75;
        $title = $meter->isWater() ? $meter->medium->meterName('de').' '.$meter->number : 'Zähler '.$meter->number;
        $this->centered($img, $bold, 40, $black, $title, $y);
        if ($meter->location) {
            $y += 55;
            $this->centered($img, $regular, 30, $grey, $meter->location, $y);
        }
        $y += 40;

        // QR-Code
        $qrSize = 620;
        $this->drawQr($img, $meter->tenantUrl(), (int) ((self::WIDTH - $qrSize) / 2), $y, $qrSize, $black, $white);
        $y += $qrSize + 65;

        $this->centered($img, $regular, 27, $black, '1. QR-Code scannen   2. Zählerstand eintragen', $y);
        $y += 45;
        $this->centered($img, $regular, 27, $black, '3. Zähler fotografieren   4. Speichern', $y);
        $y += 40;

        // Hinweis Ablesefrist (wie beim Ablesestatus)
        $day = (int) Setting::get('reading_deadline_day');
        $boxTop = $y;
        $boxBottom = self::HEIGHT - 60;
        imagefilledrectangle($img, $margin, $boxTop, self::WIDTH - $margin, $boxBottom, $greenLight);
        imagefilledrectangle($img, $margin, $boxTop, $margin + 10, $boxBottom, $green);
        $mid = (int) (($boxTop + $boxBottom) / 2);
        $this->centered($img, $bold, 34, $black, "Bitte bis zum {$day}. jedes Monats", $mid - 6);
        $this->centered($img, $bold, 34, $black, 'ablesen und melden.', $mid + 46);

        ob_start();
        $format === 'jpg' ? imagejpeg($img, null, 95) : imagepng($img, null, 6);
        imagedestroy($img);

        return (string) ob_get_clean();
    }

    public function filename(Meter $meter, string $format): string
    {
        $number = preg_replace('/[^A-Za-z0-9_-]+/', '-', $meter->number);

        return 'QR-Etikett_'.$number.'.'.$format;
    }

    private function centered(GdImage $img, string $font, int $size, int $color, string $text, int $baseline): void
    {
        $box = imagettfbbox($size, 0, $font, $text);
        $width = $box[2] - $box[0];

        // Zu lange Texte (z. B. Ort) verkleinern, damit sie auf das Etikett passen.
        $max = self::WIDTH - 140;
        if ($width > $max && $size > 12) {
            $this->centered($img, $font, $size - 2, $color, $text, $baseline);

            return;
        }

        imagettftext($img, $size, 0, (int) ((self::WIDTH - $width) / 2), $baseline, $color, $font, $text);
    }

    private function drawQr(GdImage $img, string $data, int $x, int $y, int $size, int $dark, int $light): void
    {
        $qr = new QRCode(new QROptions(['eccLevel' => EccLevel::H, 'addQuietzone' => false]));
        $matrix = $qr->addByteSegment($data)->getQRMatrix()->getBooleanMatrix();
        $modules = count($matrix);
        $module = intdiv($size, $modules);
        $offset = intdiv($size - $module * $modules, 2);

        imagefilledrectangle($img, $x, $y, $x + $size, $y + $size, $light);

        foreach ($matrix as $row => $cols) {
            foreach ($cols as $col => $on) {
                if ($on) {
                    $px = $x + $offset + $col * $module;
                    $py = $y + $offset + $row * $module;
                    imagefilledrectangle($img, $px, $py, $px + $module - 1, $py + $module - 1, $dark);
                }
            }
        }
    }
}

<?php

namespace App\Pdf;

use App\Models\Setting;
use Symfony\Component\HttpFoundation\Response;
use TCPDF;

/**
 * Gemeinsame PDF-Grundlage: A4, Kopfzeile mit Vermieter, Fußzeile mit Seitenzahl.
 * Inhalte werden als Blade-Views gerendert (automatisch escaped) und mit writeHTML ausgegeben.
 */
class Document extends TCPDF
{
    public function __construct(string $title, string $orientation = 'P')
    {
        parent::__construct($orientation, 'mm', 'A4', true, 'UTF-8', false);

        $this->setCreator(config('app.name'));
        $this->setAuthor((string) Setting::get('landlord_name'));
        $this->setTitle($title);
        // Wie im Altsystem: Logo 60 mm breit oben links, darunter eine Linie.
        $this->setMargins(15, 27, 15);
        $this->setHeaderMargin(5);
        $this->setFooterMargin(10);
        $this->setAutoPageBreak(true, 18);
        $this->setFont('helvetica', '', 10);
    }

    public function Header(): void
    {
        $logo = resource_path('pdf/logo.png');
        $bottom = 18;

        if (is_file($logo)) {
            // RGB-PNG ohne Transparenz, damit TCPDF kein GD/Imagick braucht.
            $this->Image($logo, 15, 5, 60, 0, 'PNG');
            $bottom = $this->getImageRBY() + 1;
        } else {
            $this->setFont('helvetica', 'B', 12);
            $this->Cell(0, 8, (string) Setting::get('landlord_name'), 0, 1, 'L');
        }

        $this->setDrawColor(0, 0, 0);
        $this->setLineWidth(0.3);
        $this->Line(15, $bottom, $this->getPageWidth() - 15, $bottom);
    }

    public function Footer(): void
    {
        $this->setY(-14);
        $this->setFont('helvetica', '', 7);
        $this->setTextColor(120, 120, 120);
        $this->Cell(0, 5, $this->getAliasNumPage().' / '.$this->getAliasNbPages(), 0, 0, 'R');
    }

    public function view(string $view, array $data = []): static
    {
        $this->writeHTML(view($view, $data)->render(), true, false, true, false, '');

        return $this;
    }

    public function qr(string $url, float $x, float $y, float $size): static
    {
        $this->write2DBarcode($url, 'QRCODE,H', $x, $y, $size, $size, [
            'border' => false,
            'padding' => 0,
            'fgcolor' => [0, 0, 0],
            'bgcolor' => false,
        ], 'N');

        return $this;
    }

    public function inline(string $filename): Response
    {
        return response($this->Output($filename, 'S'), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    public function content(): string
    {
        return $this->Output('document.pdf', 'S');
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Settlement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /** Alle Rechnungen und Stornos des Monats als CSV (Excel-kompatibel, Semikolon, UTF-8 mit BOM). */
    public function settlements(string $month): StreamedResponse
    {
        $rows = $this->invoices($month)->map(fn (Settlement $s) => [
            $s->formattedNumber(),
            $s->type->label(),
            $s->invoice_date?->format('d.m.Y'),
            $s->period->format('m/Y'),
            $s->tenant->debtor_number,
            $s->tenant->name,
            $s->meterNumbers(),
            $s->starts_on->format('d.m.Y'),
            $s->ends_on->format('d.m.Y'),
            $s->billed_kwh,
            $this->number($s->unit_price),
            $this->number($s->net_amount),
            $this->number($s->vat_amount),
            $this->number($s->gross_amount),
        ]);

        return $this->csv('Abrechnungen_'.$month.'.csv', [
            'Rechnungsnr.', 'Art', 'Rechnungsdatum', 'Monat', 'Kundennr.', 'Mieter', 'Zähler', 'von', 'bis',
            'kWh', 'Preis €/kWh', 'Netto', 'USt', 'Brutto',
        ], $rows);
    }

    /**
     * Vereinfachter DATEV-Buchungsstapel (Debitor an Erlöskonto, Bruttobeträge).
     * Vor dem ersten Import bitte mit der Steuerberatung abstimmen (Kontenrahmen, BU-Schlüssel).
     */
    public function datev(string $month): StreamedResponse
    {
        $account = Setting::get('datev_revenue_account');
        $taxKey = Setting::get('datev_tax_key');

        $rows = $this->invoices($month)->map(fn (Settlement $s) => [
            $this->number(abs((float) $s->gross_amount)),
            (float) $s->gross_amount >= 0 ? 'S' : 'H',
            'EUR',
            $s->tenant->debtor_number,
            $account,
            $taxKey,
            $s->invoice_date?->format('dm'),
            $s->formattedNumber(),
            mb_substr('Strom '.$s->period->format('m/Y').' '.$s->tenant->name, 0, 60),
        ]);

        return $this->csv('DATEV_Buchungsstapel_'.$month.'.csv', [
            'Umsatz (ohne Soll/Haben-Kz)', 'Soll/Haben-Kennzeichen', 'WKZ Umsatz', 'Konto', 'Gegenkonto (ohne BU-Schlüssel)',
            'BU-Schlüssel', 'Belegdatum', 'Belegfeld 1', 'Buchungstext',
        ], $rows);
    }

    /** @return Collection<int, Settlement> */
    private function invoices(string $month): Collection
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);
        $period = CarbonImmutable::createFromFormat('!Y-m', $month);

        return Settlement::query()
            ->whereDate('period', $period->toDateString())
            ->whereNotNull('invoice_number')
            ->with(['tenant', 'meter'])
            ->orderBy('invoice_number')
            ->get();
    }

    private function csv(string $filename, array $header, Collection $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header, ';');
            foreach ($rows as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function number(mixed $value): string
    {
        return number_format((float) $value, 2, ',', '');
    }
}

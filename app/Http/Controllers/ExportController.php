<?php

namespace App\Http\Controllers;

use App\Enums\SettlementType;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends Controller
{
    /**
     * Alle Abrechnungen des Monats als CSV (Excel-kompatibel, Semikolon, UTF-8 mit BOM):
     * gültige Monatsabrechnungen – mit oder ohne Rechnung – sowie Sammel- und Stornorechnungen.
     */
    public function settlements(string $month): StreamedResponse
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);
        $period = CarbonImmutable::createFromFormat('!Y-m', $month)->toDateString();

        $documents = Settlement::query()
            ->whereDate('period', $period)
            ->where(fn ($q) => $q->where(fn ($q) => $q->effective())
                ->orWhere(fn ($q) => $q->whereIn('type', [SettlementType::Collective, SettlementType::Cancellation])->numbered()))
            ->with(['tenant', 'meter', 'collectives'])
            ->orderByRaw('invoice_number is null')
            ->orderBy('invoice_number')
            ->get();

        $rows = $documents->map(fn (Settlement $s) => [
            $s->invoiceLabel(),
            $this->status($s),
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
            'Rechnungsnr.', 'Art / Status', 'Rechnungsdatum', 'Monat', 'Kundennr.', 'Mieter', 'Zähler', 'von', 'bis',
            'kWh', 'Preis €/kWh', 'Netto', 'USt', 'Brutto',
        ], $rows);
    }

    /** Alle Mieter mit Kontaktdaten, Status und Rechnungseinstellungen. */
    public function tenants(): StreamedResponse
    {
        $yesNo = fn (bool $v) => $v ? 'Ja' : 'Nein';

        $rows = Tenant::query()
            ->with(['meters' => fn ($q) => $q->orderBy('number')])
            ->orderBy('name')
            ->get()
            ->map(fn (Tenant $t) => [
                $t->name,
                $t->debtor_number,
                $t->street,
                $t->zip,
                $t->city,
                $t->phone,
                $t->email,
                $yesNo($t->is_active),
                $t->active_from?->format('d.m.Y'),
                $t->active_until?->format('d.m.Y'),
                $yesNo($t->issues_invoices),
                $yesNo($t->issues_invoices && $t->send_invoices_by_email),
                $t->price_factor !== null ? number_format((float) $t->price_factor, 3, ',', '') : 'Standard',
                $t->meters->where('is_active', true)->pluck('number')->join(', '),
            ]);

        return $this->csv('Mieter_'.now()->format('Y-m-d').'.csv', [
            'Name', 'Kundennr.', 'Straße', 'PLZ', 'Ort', 'Telefon', 'E-Mail', 'Aktiv', 'Mieter seit', 'Mieter bis',
            'Rechnungen ausstellen', 'Rechnung per E-Mail', 'Preisfaktor', 'Aktive Zähler',
        ], $rows);
    }

    private function status(Settlement $s): string
    {
        return match (true) {
            $s->type !== SettlementType::Invoice => $s->type->label().($s->isCancelled() ? ' (storniert)' : ''),
            $s->invoice_number !== null => $s->type->label(),
            $s->activeCollective() !== null => 'in Sammelrechnung',
            ! $s->tenant->issues_invoices => 'Pauschale (ohne Rechnung)',
            $s->is_invoiced => 'extern abgerechnet',
            default => 'ohne Rechnung',
        };
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

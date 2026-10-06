<?php

namespace App\Pdf;

use App\Enums\SettlementType;
use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Services\SettlementService;
use App\Services\Statistics;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class PdfFactory
{
    public function __construct(private Statistics $statistics) {}

    /** @param  Collection<int, Settlement>  $settlements */
    public function invoices(Collection $settlements): Document
    {
        $pdf = new Document(__('Monatsabrechnung'));
        $settlements = (new EloquentCollection($settlements->all()))->loadMissing(['tenant', 'meter', 'startReading', 'endReading', 'cancels']);

        foreach ($settlements as $settlement) {
            $pdf->AddPage();

            // Sammelrechnung (oder deren Storno): Positionen je Zähler und Monat.
            $collective = match (true) {
                $settlement->type === SettlementType::Collective => $settlement,
                $settlement->type === SettlementType::Cancellation && $settlement->cancels?->type === SettlementType::Collective => $settlement->cancels,
                default => null,
            };

            if ($collective) {
                $pdf->view('pdf.invoice-collective', [
                    's' => $settlement,
                    'items' => $collective->items()->with(['meter', 'startReading', 'endReading'])->get()->groupBy('meter_id'),
                    'sign' => $settlement->type === SettlementType::Cancellation ? -1 : 1,
                ] + $this->landlord());
            } else {
                $pdf->view('pdf.invoice', ['s' => $settlement] + $this->landlord());
            }

            // Hinweis zur digitalen Ablesung mit QR-Code des Zählers (nur bei genau einem Zähler).
            if ($settlement->meter?->is_active && $pdf->GetY() < 215) {
                $y = max($pdf->GetY() + 6, 200);
                $pdf->setY($y);
                $pdf->view('pdf.partials.qr-hint');
                $pdf->qr($settlement->meter->tenantUrl(), 20, $y + 10, 36);
            }
        }

        if ($settlements->isEmpty()) {
            $pdf->AddPage();
            $pdf->Write(0, __('Keine Abrechnungen vorhanden.'));
        }

        return $pdf;
    }

    public function year(Meter $meter, Tenant $tenant, int $year): Document
    {
        $settlements = Settlement::query()->effective()
            ->where('meter_id', $meter->id)
            ->where('tenant_id', $tenant->id)
            ->whereYear('period', $year)
            ->with(['startReading', 'endReading'])
            ->orderBy('period')
            ->get();

        $pdf = new Document(__('Jahresübersicht :year', ['year' => $year]));
        $pdf->AddPage();
        $pdf->view('pdf.year', compact('meter', 'tenant', 'year', 'settlements') + $this->landlord());

        return $pdf;
    }

    /** Jahresübersicht eines Mieters über alle seine Zähler (je Zähler eine Seite). */
    public function tenantYear(Tenant $tenant, int $year): Document
    {
        $groups = Settlement::query()->effective()
            ->where('tenant_id', $tenant->id)
            ->whereYear('period', $year)
            ->with(['meter', 'startReading', 'endReading', 'collectives'])
            ->orderBy('period')
            ->get()
            ->groupBy('meter_id');

        $pdf = new Document(__('Jahresübersicht :year', ['year' => $year]));

        foreach ($groups as $settlements) {
            $pdf->AddPage();
            $pdf->view('pdf.year', [
                'meter' => $settlements->first()->meter,
                'tenant' => $tenant,
                'year' => $year,
                'settlements' => $settlements,
            ] + $this->landlord());
        }

        if ($groups->isEmpty()) {
            $pdf->AddPage();
            $pdf->Write(0, __('Keine Abrechnungen vorhanden.'));
        }

        return $pdf;
    }

    public function consumption(int $year): Document
    {
        $groups = Settlement::query()->effective()
            ->whereYear('period', $year)
            ->with(['tenant', 'meter', 'startReading', 'endReading'])
            ->orderBy('period')
            ->get()
            ->groupBy(fn (Settlement $s) => $s->meter_id.'-'.$s->tenant_id);

        $pdf = new Document(__('Verbrauchsbericht :year', ['year' => $year]));

        foreach ($groups as $settlements) {
            $first = $settlements->first();
            $pdf->AddPage();
            $pdf->view('pdf.year', [
                'meter' => $first->meter,
                'tenant' => $first->tenant,
                'year' => $year,
                'settlements' => $settlements,
            ] + $this->landlord());
        }

        if ($groups->isEmpty()) {
            $pdf->AddPage();
            $pdf->Write(0, __('Keine Abrechnungen vorhanden.'));
        }

        return $pdf;
    }

    public function mainMeters(CarbonImmutable $month): Document
    {
        $meters = Meter::query()->main()->electricity()->active()->orderBy('number')->get()->map(fn (Meter $meter) => [
            'meter' => $meter,
            'price' => ElectricityPrice::query()->where('meter_id', $meter->id)->whereDate('month', $month->toDateString())->first(),
            'settlements' => Settlement::query()->effective()
                ->where('main_meter_id', $meter->id)
                ->whereDate('period', $month->toDateString())
                ->with(['meter', 'tenant'])
                ->get(),
        ]);

        $pdf = new Document(__('Bericht Hauptzähler'));
        $pdf->AddPage();
        $pdf->view('pdf.main-meters', compact('month', 'meters'));

        return $pdf;
    }

    /** Übersicht aller Zähler/Mieter eines Monats mit Abrechnungsstatus (zur Kontrolle). */
    public function monthOverview(CarbonImmutable $month): Document
    {
        $candidates = app(SettlementService::class)->candidates($month);
        $candidates->pluck('settlement')->filter()
            ->each(fn (Settlement $s) => $s->loadMissing(['startReading', 'endReading', 'collectives']));

        $collectives = Settlement::query()
            ->where('type', SettlementType::Collective)
            ->whereNull('cancelled_at')
            ->whereDate('period', $month->toDateString())
            ->with('tenant')
            ->orderBy('invoice_number')
            ->get();

        $pdf = new Document(__('Abrechnungsübersicht'), 'L');
        $pdf->AddPage();
        $pdf->view('pdf.month-overview', compact('month', 'candidates', 'collectives'));

        return $pdf;
    }

    public function difference(int $year): Document
    {
        $start = CarbonImmutable::create($year, 1, 1);
        $comparison = $this->statistics->mainMeterComparison($start, $start->endOfYear(), onlyActive: true);

        $pdf = new Document(__('Abweichungsbericht :year', ['year' => $year]));
        $pdf->AddPage();
        $pdf->view('pdf.difference', compact('year', 'comparison'));

        return $pdf;
    }

    /** Liste für den Hausmeister: 5 Zähler pro Seite mit QR-Code. */
    public function qrList(): Document
    {
        $meters = Meter::query()->active()->with(['tenant', 'parent'])->orderBy('number')->get();
        $pdf = new Document(__('QR-Code-Liste'));

        foreach ($meters->chunk(5) as $page => $chunk) {
            $pdf->AddPage();
            $pdf->view('pdf.qr-list-header', ['date' => now()]);
            $top = $pdf->GetY() + 2;

            foreach ($chunk->values() as $i => $meter) {
                $y = $top + $i * 46;
                $pdf->setY($y);
                $pdf->view('pdf.qr-row', ['meter' => $meter, 'number' => $page * 5 + $i + 1]);
                $pdf->qr($meter->tenantUrl(), 161, $y + 9.5, 31);
            }
        }

        return $pdf;
    }

    /** Ein Etikett pro Seite zum Aufkleben am Zähler. */
    public function qrLabels(): Document
    {
        $meters = Meter::query()->active()->with('tenant')->orderBy('number')->get();
        $pdf = new Document(__('QR-Code-Etiketten'));

        foreach ($meters as $meter) {
            $pdf->AddPage();
            $pdf->view('pdf.qr-label', ['meter' => $meter, 'deadline' => Setting::get('reading_deadline_day')]);
            $pdf->qr($meter->tenantUrl(), 55, $pdf->GetY() + 4, 100);
        }

        return $pdf;
    }

    private function landlord(): array
    {
        return [
            'landlord' => [
                'name' => Setting::get('landlord_name'),
                'street' => Setting::get('landlord_street'),
                'city' => Setting::get('landlord_city'),
                'vat_id' => Setting::get('landlord_vat_id'),
            ],
            'site' => Setting::get('site_address'),
        ];
    }
}

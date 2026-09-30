<?php

namespace App\Http\Controllers;

use App\Models\Meter;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Pdf\PdfFactory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class PdfController extends Controller
{
    public function __construct(private PdfFactory $pdf) {}

    public function invoice(Settlement $settlement): Response
    {
        Gate::authorize('view-finance');

        return $this->pdf->invoices(collect([$settlement]))
            ->inline(str($settlement->tenant->name)->slug().'_'.$settlement->period->format('Y-m').'_'.$settlement->formattedNumber().'.pdf');
    }

    public function invoices(string $month): Response
    {
        Gate::authorize('view-finance');
        $period = $this->month($month);

        $settlements = Settlement::query()
            ->whereDate('period', $period->toDateString())
            ->whereNotNull('invoice_number')
            ->orderBy('invoice_number')
            ->get();

        return $this->pdf->invoices($settlements)->inline('Rechnungen_'.$period->format('Y-m').'.pdf');
    }

    public function overview(string $month): Response
    {
        Gate::authorize('view-finance');

        return $this->pdf->monthOverview($this->month($month))->inline('Abrechnungen_'.$month.'.pdf');
    }

    public function year(Meter $meter, Tenant $tenant, int $year): Response
    {
        Gate::authorize('view-finance');

        return $this->pdf->year($meter, $tenant, $year)
            ->inline('Jahresuebersicht_'.str($tenant->name)->slug().'_'.$year.'.pdf');
    }

    public function consumption(int $year): Response
    {
        Gate::authorize('view-finance');

        return $this->pdf->consumption($year)->inline('Verbrauchsbericht_'.$year.'.pdf');
    }

    public function mainMeters(string $month): Response
    {
        Gate::authorize('view-finance');

        return $this->pdf->mainMeters($this->month($month))->inline('Hauptzaehler_'.$month.'.pdf');
    }

    public function difference(int $year): Response
    {
        Gate::authorize('view-finance');

        return $this->pdf->difference($year)->inline('Abweichungsbericht_'.$year.'.pdf');
    }

    public function qrList(): Response
    {
        return $this->pdf->qrList()->inline('QR_Codes_Liste_'.now()->format('Y-m-d').'.pdf');
    }

    public function qrLabels(): Response
    {
        Gate::authorize('manage');

        return $this->pdf->qrLabels()->inline('QR_Code_Etiketten_'.now()->format('Y-m-d').'.pdf');
    }

    private function month(string $month): CarbonImmutable
    {
        abort_unless(preg_match('/^\d{4}-\d{2}$/', $month) === 1, 404);

        return CarbonImmutable::createFromFormat('!Y-m', $month);
    }
}

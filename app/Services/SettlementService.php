<?php

namespace App\Services;

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Enums\SettlementType;
use App\Models\ElectricityPrice;
use App\Models\MeterAssignment;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class SettlementService
{
    public function __construct(private SettlementCalculator $calculator) {}

    /**
     * Alle Zähler/Mieter-Paare, die im Monat abzurechnen sind.
     *
     * @return Collection<int, SettlementCandidate>
     */
    public function candidates(CarbonInterface $month): Collection
    {
        $period = CarbonImmutable::parse($month)->startOfMonth();
        $nextMonth = $period->addMonthNoOverflow();

        $assignments = MeterAssignment::query()
            ->overlapping($period, $nextMonth)
            ->with(['meter', 'tenant'])
            ->get()
            ->filter(fn (MeterAssignment $a) => $a->meter && $a->tenant)
            ->sortBy(fn (MeterAssignment $a) => [$a->tenant->name, $a->meter->number]);

        $meterIds = $assignments->pluck('meter_id')->unique();

        $readings = Reading::query()
            ->whereIn('meter_id', $meterIds)
            ->valid()
            ->where('read_on', '>=', $period->toDateString())
            ->orderBy('read_on')
            ->get()
            ->groupBy('meter_id');

        $prices = ElectricityPrice::query()
            ->whereDate('month', $period->toDateString())
            ->get()
            ->keyBy('meter_id');

        $settlements = Settlement::query()
            ->effective()
            ->whereDate('period', $period->toDateString())
            ->whereIn('meter_id', $meterIds)
            ->get()
            ->keyBy(fn (Settlement $s) => $s->meter_id.'-'.$s->tenant_id);

        return $assignments->map(function (MeterAssignment $assignment) use ($period, $nextMonth, $readings, $prices, $settlements) {
            $from = $assignment->starts_on->greaterThan($period) ? CarbonImmutable::parse($assignment->starts_on) : $period;
            $endsOn = $assignment->ends_on && $assignment->ends_on->lessThan($nextMonth)
                ? CarbonImmutable::parse($assignment->ends_on)
                : $nextMonth;

            $meterReadings = $readings->get($assignment->meter_id, collect());

            $start = $meterReadings->first(fn (Reading $r) => $r->is_base
                && $r->read_on->greaterThanOrEqualTo($from)
                && $r->read_on->lessThan($endsOn));

            $samples = $start
                ? $meterReadings->filter(fn (Reading $r) => $r->read_on->greaterThan($start->read_on) && $r->value >= $start->value)->values()
                : collect();

            return new SettlementCandidate(
                period: $period,
                meter: $assignment->meter,
                tenant: $assignment->tenant,
                endsOn: $endsOn,
                startReading: $start,
                samples: $samples,
                price: $prices->get($assignment->meter->priceMeterId()),
                settlement: $settlements->get($assignment->meter_id.'-'.$assignment->tenant_id),
            );
        })->values();
    }

    public function candidate(CarbonInterface $month, int $meterId, int $tenantId): ?SettlementCandidate
    {
        return $this->candidates($month)
            ->first(fn (SettlementCandidate $c) => $c->meter->id === $meterId && $c->tenant->id === $tenantId);
    }

    public function preview(SettlementCandidate $candidate, Reading $sample, string|float|null $priceFactor = null): SettlementCalculation
    {
        if (! $candidate->startReading || ! $candidate->price) {
            throw new RuntimeException(__('Die Abrechnung ist noch nicht möglich: :status', ['status' => $candidate->statusLabel()]));
        }

        return $this->calculator->calculate(
            startValue: $candidate->startReading->value,
            startsOn: $candidate->startReading->read_on,
            sampleValue: $sample->value,
            sampleDate: $sample->read_on,
            endsOn: $candidate->endsOn,
            meterFactor: $candidate->meter->factor,
            basePrice: $candidate->price->net_price,
            priceFactor: $priceFactor ?? $candidate->tenant->effectivePriceFactor(),
            vatRate: Setting::get('vat_rate'),
        );
    }

    public function settle(
        SettlementCandidate $candidate,
        Reading $sample,
        string|float|null $priceFactor,
        bool $invoice,
        ?User $user,
    ): Settlement {
        if ($candidate->status() !== SettlementCandidate::READY) {
            throw new RuntimeException(__('Die Abrechnung ist nicht möglich: :status', ['status' => $candidate->statusLabel()]));
        }

        if (! $candidate->samples->contains('id', $sample->id)) {
            throw new RuntimeException(__('Die gewählte Ablesung gehört nicht zu dieser Abrechnung.'));
        }

        $calc = $this->preview($candidate, $sample, $priceFactor);

        return DB::transaction(function () use ($candidate, $sample, $calc, $invoice, $user) {
            $alreadySettled = Settlement::query()->effective()
                ->where('meter_id', $candidate->meter->id)
                ->where('tenant_id', $candidate->tenant->id)
                ->whereDate('period', $candidate->period->toDateString())
                ->lockForUpdate()
                ->exists();

            if ($alreadySettled) {
                throw new RuntimeException(__('Dieser Zähler wurde für den Monat bereits abgerechnet.'));
            }

            $endReading = $this->endReading($candidate, $sample, $calc, $user);

            return Settlement::create([
                'type' => SettlementType::Invoice,
                'invoice_number' => $invoice ? $this->nextInvoiceNumber() : null,
                'invoice_date' => $invoice ? now()->toDateString() : null,
                'period' => $candidate->period->toDateString(),
                'tenant_id' => $candidate->tenant->id,
                'meter_id' => $candidate->meter->id,
                'main_meter_id' => $candidate->meter->priceMeterId(),
                'start_reading_id' => $candidate->startReading->id,
                'end_reading_id' => $endReading->id,
                'sample_reading_id' => $sample->id,
                'starts_on' => $calc->startsOn->toDateString(),
                'ends_on' => $calc->endsOn->toDateString(),
                'consumption_kwh' => $calc->consumptionKwh,
                'meter_factor' => $calc->meterFactor,
                'billed_kwh' => $calc->billedKwh,
                'base_price' => $calc->basePrice,
                'price_factor' => $calc->priceFactor,
                'unit_price' => $calc->unitPrice(),
                'net_amount' => $calc->netAmount(),
                'vat_rate' => $calc->vatRate,
                'vat_amount' => $calc->vatAmount(),
                'gross_amount' => $calc->grossAmount(),
                'is_invoiced' => $invoice,
                'created_by' => $user?->id,
            ]);
        });
    }

    /**
     * Rechnet alle abrechnungsbereiten Zähler des Monats mit der Standardablesung ab.
     *
     * @return array{settled: int, errors: array<int, string>}
     */
    public function settleAll(CarbonInterface $month, bool $invoice, ?User $user): array
    {
        $settled = 0;
        $errors = [];

        foreach ($this->candidates($month)->filter->isReady() as $candidate) {
            try {
                $this->settle($candidate, $candidate->defaultSample(), null, $invoice, $user);
                $settled++;
            } catch (RuntimeException|\InvalidArgumentException $e) {
                $errors[] = $candidate->meter->number.' / '.$candidate->tenant->name.': '.$e->getMessage();
            }
        }

        return ['settled' => $settled, 'errors' => $errors];
    }

    /**
     * Storniert eine Abrechnung. Möglich nur, solange der berechnete Endstand
     * nicht schon Anfangsstand einer späteren Abrechnung ist.
     */
    public function cancel(Settlement $settlement, ?User $user): ?Settlement
    {
        if ($settlement->type !== SettlementType::Invoice || $settlement->isCancelled()) {
            throw new RuntimeException(__('Diese Abrechnung kann nicht storniert werden.'));
        }

        $hasFollowUp = Settlement::query()->effective()
            ->where('start_reading_id', $settlement->end_reading_id)
            ->exists();

        if ($hasFollowUp) {
            throw new RuntimeException(__('Bitte zuerst die Abrechnung des Folgemonats stornieren.'));
        }

        return DB::transaction(function () use ($settlement, $user) {
            $settlement->update(['cancelled_at' => now()]);

            $cancellation = null;

            if ($settlement->is_invoiced) {
                $cancellation = Settlement::create([
                    ...collect($settlement->getAttributes())->except([
                        'id', 'invoice_number', 'invoice_date', 'cancelled_at', 'emailed_at',
                        'created_at', 'updated_at', 'legacy_id', 'created_by',
                    ])->all(),
                    'type' => SettlementType::Cancellation,
                    'invoice_number' => $this->nextInvoiceNumber(),
                    'invoice_date' => now()->toDateString(),
                    'cancels_id' => $settlement->id,
                    'consumption_kwh' => -$settlement->consumption_kwh,
                    'billed_kwh' => -$settlement->billed_kwh,
                    'net_amount' => bcmul((string) $settlement->net_amount, '-1', 2),
                    'vat_amount' => bcmul((string) $settlement->vat_amount, '-1', 2),
                    'gross_amount' => bcmul((string) $settlement->gross_amount, '-1', 2),
                    'created_by' => $user?->id,
                ]);
            }

            $end = $settlement->endReading;

            if ($end && $end->source === ReadingSource::System
                && ! Settlement::query()->effective()->where('end_reading_id', $end->id)->exists()) {
                $settlement->update(['end_reading_id' => null]);
                $cancellation?->update(['end_reading_id' => null]);
                $end->delete();
            }

            return $cancellation;
        });
    }

    /**
     * Endstand der Periode = Anfangsstand der nächsten Periode.
     * Gibt es eine echte Ablesung genau am Periodenende, wird sie verwendet,
     * sonst wird der berechnete Stand als Systemablesung gespeichert.
     */
    private function endReading(SettlementCandidate $candidate, Reading $sample, SettlementCalculation $calc, ?User $user): Reading
    {
        $existing = Reading::query()
            ->where('meter_id', $candidate->meter->id)
            ->valid()
            ->whereDate('read_on', $calc->endsOn->toDateString())
            ->orderByDesc('is_base')
            ->first();

        if ($existing && ! $calc->isExtrapolated) {
            $existing->update(['is_base' => true]);

            return $existing;
        }

        if ($existing && $existing->is_base) {
            return $existing;
        }

        return Reading::create([
            'meter_id' => $candidate->meter->id,
            'tenant_id' => $candidate->tenant->id,
            'value' => $calc->endValue,
            'read_on' => $calc->endsOn->toDateString(),
            'is_base' => true,
            'source' => ReadingSource::System,
            'reader_name' => 'System - berechnet',
            'status' => ReadingStatus::Approved,
            'created_by' => $user?->id,
        ]);
    }

    /** Fortlaufende Rechnungsnummer, gegen parallele Vergabe gesperrt. */
    public function nextInvoiceNumber(): int
    {
        return DB::transaction(function () {
            Setting::query()->firstOrCreate(['key' => 'invoice_counter'], ['value' => Setting::DEFAULTS['invoice_counter']]);

            $counter = Setting::query()->whereKey('invoice_counter')->lockForUpdate()->first();
            $next = max((int) $counter->value, (int) Settlement::max('invoice_number')) + 1;

            $counter->update(['value' => (string) $next]);
            Setting::forgetCache();

            return $next;
        });
    }
}

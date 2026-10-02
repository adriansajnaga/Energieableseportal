<?php

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Enums\SettlementType;
use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Services\MeterService;
use App\Services\ReadingService;
use App\Services\SettlementCandidate;
use App\Services\SettlementService;
use App\Services\Statistics;
use App\Services\TenantService;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->main = Meter::factory()->main()->create();
    $this->tenant = Tenant::factory()->create(['name' => 'Alpha GmbH']);
    $this->meter = app(MeterService::class)->create(
        ['number' => 'Z-1', 'parent_id' => $this->main->id, 'tenant_id' => $this->tenant->id],
        1000,
        CarbonImmutable::parse('2024-06-01'),
        null,
    );
    ElectricityPrice::create(['meter_id' => $this->main->id, 'month' => '2024-06-01', 'net_price' => '0.25']);
    ElectricityPrice::create(['meter_id' => $this->main->id, 'month' => '2024-07-01', 'net_price' => '0.30']);

    $this->service = app(SettlementService::class);
    $this->readings = app(ReadingService::class);
});

function reading(Meter $meter, int $value, string $date): Reading
{
    return app(ReadingService::class)->record($meter, $value, CarbonImmutable::parse($date), ReadingSource::Caretaker);
}

it('lists the meter as missing a reading until one exists', function () {
    $candidate = $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole();

    expect($candidate->status())->toBe(SettlementCandidate::MISSING_READING);

    reading($this->meter, 1100, '2024-06-21');

    expect($this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole()->status())
        ->toBe(SettlementCandidate::READY);
});

it('settles a month and creates the base reading for the next month', function () {
    $sample = reading($this->meter, 1100, '2024-06-21');
    $candidate = $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole();

    $settlement = $this->service->settle($candidate, $sample, null, true, null);

    expect($settlement->consumption_kwh)->toBe(150)
        ->and((string) $settlement->net_amount)->toBe('42.00')
        ->and($settlement->invoice_number)->toBe(1)
        ->and($settlement->formattedNumber())->toBe('E-0001')
        ->and($settlement->endReading->value)->toBe(1150)
        ->and($settlement->endReading->read_on->toDateString())->toBe('2024-07-01')
        ->and($settlement->endReading->is_base)->toBeTrue();

    // Der berechnete Endstand ist der Anfangsstand im Juli.
    reading($this->meter, 1300, '2024-07-31');
    $july = $this->service->candidates(CarbonImmutable::parse('2024-07-01'))->sole();

    expect($july->startReading->id)->toBe($settlement->end_reading_id)
        ->and($july->status())->toBe(SettlementCandidate::READY);

    $second = $this->service->settle($july, $july->defaultSample(), null, true, null);
    expect($second->invoice_number)->toBe(2);
});

it('does not settle the same month twice', function () {
    $sample = reading($this->meter, 1100, '2024-06-21');
    $candidate = $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole();
    $this->service->settle($candidate, $sample, null, true, null);

    $this->service->settle($candidate, $sample, null, true, null);
})->throws(RuntimeException::class);

it('cancels a settlement with a cancellation invoice and removes the computed reading', function () {
    $sample = reading($this->meter, 1100, '2024-06-21');
    $settlement = $this->service->settle(
        $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole(), $sample, null, true, null
    );
    $endReadingId = $settlement->end_reading_id;

    $cancellation = $this->service->cancel($settlement, null);

    expect($settlement->fresh()->isCancelled())->toBeTrue()
        ->and($cancellation->type)->toBe(SettlementType::Cancellation)
        ->and((string) $cancellation->gross_amount)->toBe('-49.98')
        ->and($cancellation->invoice_number)->toBe(2)
        ->and(Reading::find($endReadingId))->toBeNull()
        ->and($this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole()->status())
        ->toBe(SettlementCandidate::READY);
});

it('refuses to cancel when the following month is already settled', function () {
    $june = $this->service->settle(
        $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole(), reading($this->meter, 1100, '2024-06-21'), null, true, null
    );
    reading($this->meter, 1300, '2024-07-31');
    $july = $this->service->candidates(CarbonImmutable::parse('2024-07-01'))->sole();
    $this->service->settle($july, $july->defaultSample(), null, true, null);

    $this->service->cancel($june, null);
})->throws(RuntimeException::class);

it('splits the month on a tenant change', function () {
    $newTenant = Tenant::factory()->create(['name' => 'Beta GmbH']);
    app(MeterService::class)->changeTenant($this->meter, $newTenant, CarbonImmutable::parse('2024-06-16'), 1080, null);
    reading($this->meter, 1130, '2024-06-26');

    $candidates = $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->keyBy(fn ($c) => $c->tenant->name);

    $old = $candidates['Alpha GmbH'];
    $new = $candidates['Beta GmbH'];

    expect($old->endsOn->toDateString())->toBe('2024-06-16')
        ->and($new->startReading->value)->toBe(1080);

    $oldSettlement = $this->service->settle($old, $old->defaultSample(), null, true, null);
    $newSettlement = $this->service->settle($new, $new->defaultSample(), null, true, null);

    // Alt: exakt 80 kWh bis zum Wechsel. Neu: 50 kWh in 10 Tagen -> 5/Tag x 15 Tage = 75 kWh.
    expect($oldSettlement->consumption_kwh)->toBe(80)
        ->and($newSettlement->consumption_kwh)->toBe(75)
        ->and($newSettlement->endReading->value)->toBe(1155);
});

it('flags implausible readings and ignores them until approved', function () {
    $low = reading($this->meter, 900, '2024-06-10');

    expect($low->status)->toBe(ReadingStatus::Pending)
        ->and($low->check_note)->not->toBeNull()
        ->and($this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole()->status())
        ->toBe(SettlementCandidate::MISSING_READING);
});

it('settles all ready meters at once', function () {
    $other = app(MeterService::class)->create(
        ['number' => 'Z-2', 'parent_id' => $this->main->id, 'tenant_id' => $this->tenant->id],
        500,
        CarbonImmutable::parse('2024-06-01'),
        null,
    );
    reading($this->meter, 1100, '2024-06-21');
    reading($other, 560, '2024-07-01');

    $result = $this->service->settleAll(CarbonImmutable::parse('2024-06-01'), true, null);

    expect($result['settled'])->toBe(2)->and($result['errors'])->toBe([]);
});

it('collects several months and meters of one tenant into one invoice', function () {
    $second = app(MeterService::class)->create(
        ['number' => 'Z-2', 'parent_id' => $this->main->id, 'tenant_id' => $this->tenant->id, 'factor' => 60],
        500,
        CarbonImmutable::parse('2024-06-01'),
        null,
    );

    reading($this->meter, 1100, '2024-06-21');
    reading($second, 512, '2024-07-01');
    $this->service->settleAll(CarbonImmutable::parse('2024-06-01'), false, null);

    reading($this->meter, 1300, '2024-07-31');
    reading($second, 520, '2024-08-01');
    $this->service->settleAll(CarbonImmutable::parse('2024-07-01'), false, null);

    $open = Settlement::query()->openForCollection()->get();
    expect($open)->toHaveCount(4)->and($open->whereNotNull('invoice_number'))->toHaveCount(0);

    $collective = $this->service->collect($open, null);

    // Summe der Monatsbeträge; USt auf die Gesamtsumme.
    $net = $open->sum(fn ($s) => (float) $s->net_amount);
    expect($collective->type)->toBe(SettlementType::Collective)
        ->and($collective->invoice_number)->toBe(1)
        ->and($collective->meter_id)->toBeNull()
        ->and((float) $collective->net_amount)->toBe(round($net, 2))
        ->and((string) $collective->vat_amount)->toBe(number_format(round($net * 0.19, 2), 2, '.', ''))
        ->and($collective->items)->toHaveCount(4)
        ->and($collective->starts_on->toDateString())->toBe('2024-06-01')
        ->and($collective->ends_on->toDateString())->toBe('2024-08-01')
        ->and(Settlement::query()->openForCollection()->count())->toBe(0)
        ->and($open->first()->fresh()->invoiceLabel())->toBe('E-0001');

    // Monat in einer Sammelrechnung kann nicht einzeln storniert werden.
    expect(fn () => $this->service->cancel($open->last()->fresh(), null))->toThrow(RuntimeException::class);

    // Storno der Sammelrechnung gibt die Monate wieder frei.
    $cancellation = $this->service->cancel($collective, null);
    expect($cancellation->invoice_number)->toBe(2)
        ->and((string) $cancellation->gross_amount)->toBe(bcmul((string) $collective->gross_amount, '-1', 2))
        ->and(Settlement::query()->openForCollection()->count())->toBe(4);

    $again = $this->service->collect(Settlement::query()->openForCollection()->get(), null);
    expect($again->invoice_number)->toBe(3);
});

it('refuses to collect settlements of different tenants or already invoiced ones', function () {
    $other = Tenant::factory()->create();
    $otherMeter = app(MeterService::class)->create(
        ['number' => 'Z-9', 'parent_id' => $this->main->id, 'tenant_id' => $other->id], 100, CarbonImmutable::parse('2024-06-01'), null,
    );
    reading($this->meter, 1100, '2024-06-21');
    reading($otherMeter, 150, '2024-06-21');
    $this->service->settleAll(CarbonImmutable::parse('2024-06-01'), false, null);

    expect(fn () => $this->service->collect(Settlement::query()->openForCollection()->get(), null))
        ->toThrow(RuntimeException::class);

    // collectAll erstellt je Mieter eine Rechnung.
    $result = $this->service->collectAll(CarbonImmutable::parse('2024-06-01'), null);
    expect($result['created'])->toBe(2)->and(Settlement::where('type', 'collective')->count())->toBe(2);

    expect(fn () => $this->service->collect(Settlement::where('type', 'invoice')->get(), null))
        ->toThrow(RuntimeException::class);
});

it('stops listing a tenant after the end of the tenancy', function () {
    app(TenantService::class)->setActiveUntil($this->tenant, CarbonImmutable::parse('2024-06-30'));

    // Juni wird noch voll abgerechnet, Juli nicht mehr.
    expect($this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole()->endsOn->toDateString())->toBe('2024-07-01')
        ->and($this->service->candidates(CarbonImmutable::parse('2024-07-01')))->toHaveCount(0)
        ->and($this->meter->fresh()->tenant_id)->toBeNull()
        ->and($this->tenant->fresh()->is_active)->toBeFalse();

    // Enddatum entfernen stellt die Zuordnung wieder her.
    app(TenantService::class)->setActiveUntil($this->tenant->fresh(), null);
    expect($this->service->candidates(CarbonImmutable::parse('2024-07-01')))->toHaveCount(1)
        ->and($this->meter->fresh()->tenant_id)->toBe($this->tenant->id)
        ->and($this->tenant->fresh()->is_active)->toBeTrue();
});

it('settles only the days until a move-out in the middle of the month', function () {
    app(TenantService::class)->setActiveUntil($this->tenant, CarbonImmutable::parse('2024-06-15'));
    reading($this->meter, 1080, '2024-06-16');

    $candidate = $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole();
    expect($candidate->endsOn->toDateString())->toBe('2024-06-16');

    $settlement = $this->service->settle($candidate, $candidate->defaultSample(), null, true, null);
    expect($settlement->consumption_kwh)->toBe(80);
});

it('deletes the last unsent invoice and reuses its number', function () {
    $sample = reading($this->meter, 1100, '2024-06-21');
    $settlement = $this->service->settle($this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole(), $sample, null, true, null);
    $endReadingId = $settlement->end_reading_id;
    expect($settlement->invoice_number)->toBe(1)->and($this->service->deletionBlocker($settlement))->toBeNull();

    $this->service->deleteInvoice($settlement);

    expect(Settlement::find($settlement->id))->toBeNull()
        ->and(Reading::find($endReadingId))->toBeNull()
        ->and(Setting::find('invoice_counter')->value)->toBe('0');

    // Monat erneut abrechnen: gleiche Nummer.
    $again = $this->service->settle($this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole(), $sample, null, true, null);
    expect($again->invoice_number)->toBe(1);
});

it('refuses to delete sent invoices or when a later number exists', function () {
    $sample = reading($this->meter, 1100, '2024-06-21');
    $june = $this->service->settle($this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole(), $sample, null, true, null);

    $june->update(['emailed_at' => now()]);
    expect($this->service->deletionBlocker($june))->not->toBeNull();
    $june->update(['emailed_at' => null]);

    reading($this->meter, 1300, '2024-07-31');
    $july = $this->service->candidates(CarbonImmutable::parse('2024-07-01'))->sole();
    $this->service->settle($july, $july->defaultSample(), null, true, null);

    expect($this->service->deletionBlocker($june->fresh()))->not->toBeNull()
        ->and(fn () => $this->service->deleteInvoice($june->fresh()))->toThrow(RuntimeException::class);
});

it('deletes a collective invoice and releases its months', function () {
    reading($this->meter, 1100, '2024-06-21');
    $this->service->settleAll(CarbonImmutable::parse('2024-06-01'), false, null);
    $collective = $this->service->collect(Settlement::query()->openForCollection()->get(), null);

    $this->service->deleteInvoice($collective);

    expect(Settlement::find($collective->id))->toBeNull()
        ->and(Settlement::query()->openForCollection()->count())->toBe(1)
        ->and($this->service->collect(Settlement::query()->openForCollection()->get(), null)->invoice_number)->toBe(1);
});

it('leaves inactive or empty main meters out of the deviation chart', function () {
    $old = Meter::factory()->main()->create(['number' => 'HZ-ALT', 'is_active' => false]);
    Meter::factory()->main()->create(['number' => 'HZ-LEER']);
    ElectricityPrice::create(['meter_id' => $old->id, 'month' => '2024-06-01', 'net_price' => '0.25', 'consumption_kwh' => 100]);

    $stats = app(Statistics::class);
    $from = CarbonImmutable::parse('2024-06-01');
    $to = CarbonImmutable::parse('2024-07-01');

    // Bericht: alle Hauptzähler mit Daten (auch inaktive), Dashboard: nur aktive.
    expect($stats->mainMeterComparison($from, $to)->pluck('meter.number')->all())->toContain('HZ-ALT')->not->toContain('HZ-LEER')
        ->and($stats->mainMeterComparison($from, $to, onlyActive: true)->pluck('meter.number')->all())->not->toContain('HZ-ALT')->not->toContain('HZ-LEER');
});

it('ignores inactive meters but settles a replaced meter until the replacement day', function () {
    $this->meter->update(['is_active' => false]);
    expect($this->service->candidates(CarbonImmutable::parse('2024-06-01')))->toHaveCount(0);

    $this->meter->update(['is_active' => true]);
    $new = app(MeterService::class)->replace($this->meter, 'Z-NEU', CarbonImmutable::parse('2024-06-15'), 1070, 0, null);

    $numbers = $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->map(fn ($c) => $c->meter->number)->sort()->values()->all();
    expect($numbers)->toBe(['Z-1', 'Z-NEU'])
        ->and($this->service->candidates(CarbonImmutable::parse('2024-07-01'))->map(fn ($c) => $c->meter->number)->all())->toBe(['Z-NEU']);
});

it('marks externally invoiced settlements without self-referencing update', function () {
    reading($this->meter, 1100, '2024-06-21');
    $this->service->settleAll(CarbonImmutable::parse('2024-06-01'), false, null);

    expect($this->service->markExternallyInvoiced(CarbonImmutable::parse('2024-06-01')))->toBe(1)
        ->and(Settlement::query()->openForCollection()->count())->toBe(0);
});

it('never creates an invoice for a flat-rate tenant', function () {
    $this->tenant->update(['issues_invoices' => false]);
    reading($this->meter, 1100, '2024-06-21');

    $candidate = $this->service->candidates(CarbonImmutable::parse('2024-06-01'))->sole();
    $settlement = $this->service->settle($candidate, $candidate->defaultSample(), null, true, null);

    expect($settlement->invoice_number)->toBeNull()
        ->and($settlement->is_invoiced)->toBeFalse()
        ->and($settlement->isFlatRate())->toBeTrue()
        ->and(Settlement::query()->openForCollection()->count())->toBe(0)
        ->and(fn () => $this->service->collect(collect([$settlement]), null))->toThrow(RuntimeException::class)
        ->and($this->service->collectAll(CarbonImmutable::parse('2024-06-01'), null)['created'])->toBe(0)
        ->and($this->service->markExternallyInvoiced(CarbonImmutable::parse('2024-06-01')))->toBe(0);
});

it('unmarks externally invoiced settlements of a year', function () {
    reading($this->meter, 1100, '2024-06-21');
    $this->service->settleAll(CarbonImmutable::parse('2024-06-01'), false, null);
    $this->service->markExternallyInvoiced(CarbonImmutable::parse('2024-06-01'));

    expect($this->service->unmarkExternallyInvoiced(2023))->toBe(0)
        ->and($this->service->unmarkExternallyInvoiced(2024))->toBe(1)
        ->and(Settlement::query()->openForCollection()->count())->toBe(1);
});

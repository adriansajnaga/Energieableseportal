<?php

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Enums\SettlementType;
use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Tenant;
use App\Services\MeterService;
use App\Services\ReadingService;
use App\Services\SettlementCandidate;
use App\Services\SettlementService;
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

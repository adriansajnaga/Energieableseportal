<?php

use App\Services\SettlementCalculation;
use App\Services\SettlementCalculator;
use Carbon\CarbonImmutable;

function calc(array $overrides = []): SettlementCalculation
{
    $args = array_merge([
        'startValue' => 1000,
        'startsOn' => CarbonImmutable::parse('2024-06-01'),
        'sampleValue' => 1100,
        'sampleDate' => CarbonImmutable::parse('2024-06-21'),
        'endsOn' => CarbonImmutable::parse('2024-07-01'),
        'meterFactor' => 1,
        'basePrice' => '0.25',
        'priceFactor' => '1.1',
        'vatRate' => '19',
    ], $overrides);

    return (new SettlementCalculator)->calculate(...$args);
}

it('extrapolates the daily consumption to the whole month like the legacy system', function () {
    $result = calc();

    // 100 kWh in 20 Tagen = 5 kWh/Tag x 30 Tage = 150 kWh
    expect($result->consumptionBetweenReadings)->toBe(100)
        ->and($result->daysBetweenReadings)->toBe(20)
        ->and($result->daysInPeriod)->toBe(30)
        ->and($result->consumptionKwh)->toBe(150)
        ->and($result->endValue)->toBe(1150)
        ->and($result->isExtrapolated)->toBeTrue();
});

it('rounds the extrapolated consumption up to whole kWh', function () {
    // 10 kWh in 3 Tagen x 30 Tage = 100 kWh genau, 11 kWh -> 110, 7 kWh -> 70
    expect(calc(['sampleValue' => 1007, 'sampleDate' => CarbonImmutable::parse('2024-06-04')])->consumptionKwh)->toBe(70)
        // 7 kWh in 9 Tagen x 30 = 23,33 -> 24
        ->and(calc(['sampleValue' => 1007, 'sampleDate' => CarbonImmutable::parse('2024-06-10')])->consumptionKwh)->toBe(24);
});

it('uses the exact value when the reading is on the last day of the period', function () {
    $result = calc(['sampleValue' => 1234, 'sampleDate' => CarbonImmutable::parse('2024-07-01')]);

    expect($result->consumptionKwh)->toBe(234)
        ->and($result->isExtrapolated)->toBeFalse();
});

it('computes prices in whole cents without floating point errors', function () {
    // 0,25 x 1,1 = 0,275 -> aufgerundet 0,28 (JS lieferte hier wegen 27.500000000000004 zufällig dasselbe)
    $result = calc();

    expect($result->unitPriceCents)->toBe(28)
        ->and($result->netCents)->toBe(150 * 28)
        ->and($result->netAmount())->toBe('42.00')
        ->and($result->vatAmount())->toBe('7.98')
        ->and($result->grossAmount())->toBe('49.98');

    // 0,30 x 1,1 = 0,33 genau; Gleitkomma ergibt 33.00000000000001 und hätte auf 0,34 aufgerundet.
    expect(calc(['basePrice' => '0.30'])->unitPriceCents)->toBe(33);
});

it('multiplies the charge with the meter factor', function () {
    $result = calc(['meterFactor' => 40]);

    expect($result->consumptionKwh)->toBe(150)
        ->and($result->billedKwh)->toBe(6000)
        ->and($result->netAmount())->toBe('1680.00');
});

it('handles a period that starts mid month (tenant change)', function () {
    $result = calc([
        'startsOn' => CarbonImmutable::parse('2024-06-16'),
        'sampleValue' => 1050,
        'sampleDate' => CarbonImmutable::parse('2024-06-26'),
    ]);

    // 5 kWh/Tag x 15 Tage (16.06. bis 01.07.)
    expect($result->daysInPeriod)->toBe(15)->and($result->consumptionKwh)->toBe(75);
});

it('rejects readings that are lower than the start value', function () {
    calc(['sampleValue' => 999]);
})->throws(InvalidArgumentException::class);

it('rejects readings on the start date', function () {
    calc(['sampleDate' => CarbonImmutable::parse('2024-06-01')]);
})->throws(InvalidArgumentException::class);

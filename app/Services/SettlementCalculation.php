<?php

namespace App\Services;

use Carbon\CarbonImmutable;

/**
 * Ergebnis einer Abrechnungsberechnung. Alle Geldbeträge in Cent.
 */
final readonly class SettlementCalculation
{
    public function __construct(
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public int $startValue,
        public int $sampleValue,
        public CarbonImmutable $sampleDate,
        public int $consumptionBetweenReadings,
        public int $daysBetweenReadings,
        public int $daysInPeriod,
        public float $dailyConsumption,
        public int $consumptionKwh,
        public int $endValue,
        public int $meterFactor,
        public int $billedKwh,
        public string $basePrice,
        public string $priceFactor,
        public int $unitPriceCents,
        public int $netCents,
        public string $vatRate,
        public int $vatCents,
        public int $grossCents,
        public bool $isExtrapolated,
    ) {}

    public function unitPrice(): string
    {
        return self::cents($this->unitPriceCents);
    }

    public function netAmount(): string
    {
        return self::cents($this->netCents);
    }

    public function vatAmount(): string
    {
        return self::cents($this->vatCents);
    }

    public function grossAmount(): string
    {
        return self::cents($this->grossCents);
    }

    public static function cents(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign.intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }
}

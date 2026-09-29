<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Berechnet eine Stromabrechnung nach dem Verfahren des Altsystems:
 *
 *  1. Verbrauch zwischen Anfangsstand und einer späteren Ablesung,
 *  2. durchschnittlicher Tagesverbrauch x Tage des Abrechnungszeitraums, aufgerundet auf volle kWh,
 *  3. Preis für den Mieter = Strompreis x Preisfaktor, aufgerundet auf volle Cent,
 *  4. Entgelt netto = kWh x Preis x Zählerfaktor.
 *
 * Liegt die Ablesung genau am Ende des Zeitraums, ist das Ergebnis exakt (keine Hochrechnung).
 * Gerechnet wird mit Ganzzahlen (kWh, Cent), damit keine Rundungsfehler durch Gleitkommazahlen entstehen.
 */
class SettlementCalculator
{
    public function calculate(
        int $startValue,
        CarbonInterface $startsOn,
        int $sampleValue,
        CarbonInterface $sampleDate,
        CarbonInterface $endsOn,
        int $meterFactor,
        string|float $basePrice,
        string|float $priceFactor,
        string|float $vatRate,
    ): SettlementCalculation {
        $startsOn = CarbonImmutable::parse($startsOn)->startOfDay();
        $sampleDate = CarbonImmutable::parse($sampleDate)->startOfDay();
        $endsOn = CarbonImmutable::parse($endsOn)->startOfDay();

        $daysBetween = (int) $startsOn->diffInDays($sampleDate);
        $daysInPeriod = (int) $startsOn->diffInDays($endsOn);

        if ($daysBetween <= 0) {
            throw new InvalidArgumentException(__('Die Ablesung muss nach dem Anfangsstand liegen.'));
        }

        if ($daysInPeriod <= 0) {
            throw new InvalidArgumentException(__('Der Abrechnungszeitraum ist leer.'));
        }

        $consumption = $sampleValue - $startValue;

        if ($consumption < 0) {
            throw new InvalidArgumentException(__('Der Zählerstand ist kleiner als der Anfangsstand.'));
        }

        // ceil(consumption / daysBetween * daysInPeriod) ohne Gleitkomma.
        $consumptionKwh = intdiv($consumption * $daysInPeriod + $daysBetween - 1, $daysBetween);

        $basePrice = $this->decimal($basePrice, 5);
        $priceFactor = $this->decimal($priceFactor, 3);
        $vatRate = $this->decimal($vatRate, 2);

        $unitPriceCents = $this->ceilCents(bcmul($basePrice, $priceFactor, 10));
        $netCents = $consumptionKwh * $unitPriceCents * $meterFactor;
        $vatCents = (int) round(((float) bcmul((string) $netCents, $vatRate, 4)) / 100, 0, PHP_ROUND_HALF_UP);

        return new SettlementCalculation(
            startsOn: $startsOn,
            endsOn: $endsOn,
            startValue: $startValue,
            sampleValue: $sampleValue,
            sampleDate: $sampleDate,
            consumptionBetweenReadings: $consumption,
            daysBetweenReadings: $daysBetween,
            daysInPeriod: $daysInPeriod,
            dailyConsumption: $consumption / $daysBetween,
            consumptionKwh: $consumptionKwh,
            endValue: $startValue + $consumptionKwh,
            meterFactor: $meterFactor,
            billedKwh: $consumptionKwh * $meterFactor,
            basePrice: $basePrice,
            priceFactor: $priceFactor,
            unitPriceCents: $unitPriceCents,
            netCents: $netCents,
            vatRate: $vatRate,
            vatCents: $vatCents,
            grossCents: $netCents + $vatCents,
            isExtrapolated: ! $sampleDate->equalTo($endsOn),
        );
    }

    private function ceilCents(string $euro): int
    {
        $cents = bcmul($euro, '100', 10);
        $whole = bcadd($cents, '0', 0);

        return (int) (bccomp($cents, $whole, 10) > 0 ? bcadd($whole, '1', 0) : $whole);
    }

    private function decimal(string|float $value, int $scale): string
    {
        $value = str_replace(',', '.', trim((string) $value));

        if (! is_numeric($value)) {
            throw new InvalidArgumentException("Ungültige Zahl: {$value}");
        }

        return bcadd($value, '0', $scale);
    }
}

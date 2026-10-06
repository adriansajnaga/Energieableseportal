<?php

namespace App\Enums;

/**
 * Medium eines Zählers. Zählerstände werden immer als Ganzzahl gespeichert:
 * Strom in kWh, Wasser in Litern (Anzeige und Eingabe in m³ mit 3 Nachkommastellen).
 */
enum Medium: string
{
    case Electricity = 'electricity';
    case ColdWater = 'cold_water';
    case HotWater = 'hot_water';

    public function label(): string
    {
        return match ($this) {
            self::Electricity => __('Strom'),
            self::ColdWater => __('Kaltwasser'),
            self::HotWater => __('Warmwasser'),
        };
    }

    /** Bezeichnung des Zählers, z. B. für Etiketten (Etiketten und PDFs immer deutsch: $locale = 'de'). */
    public function meterName(?string $locale = null): string
    {
        return match ($this) {
            self::Electricity => __('Stromzähler', locale: $locale),
            self::ColdWater => __('Kaltwasserzähler', locale: $locale),
            self::HotWater => __('Warmwasserzähler', locale: $locale),
        };
    }

    /** Akzentfarbe (RGB) für QR-Etiketten: Strom grün wie bisher, Kaltwasser blau, Warmwasser rot. */
    public function labelRgb(): array
    {
        return match ($this) {
            self::Electricity => ['accent' => [115, 179, 86], 'light' => [232, 245, 233]],
            self::ColdWater => ['accent' => [37, 99, 235], 'light' => [219, 234, 254]],
            self::HotWater => ['accent' => [220, 38, 38], 'light' => [254, 226, 226]],
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Electricity => 'yellow',
            self::ColdWater => 'sky',
            self::HotWater => 'rose',
        };
    }

    public function isWater(): bool
    {
        return $this !== self::Electricity;
    }

    public function unit(): string
    {
        return $this->isWater() ? 'm³' : 'kWh';
    }

    /** Gespeicherte Einheiten je angezeigter Einheit (1 m³ = 1000 l). */
    public function scale(): int
    {
        return $this->isWater() ? 1000 : 1;
    }

    public function decimals(): int
    {
        return $this->isWater() ? 3 : 0;
    }

    /** Eichfrist in Jahren (Mess- und Eichverordnung), null = wird nicht überwacht. */
    public function calibrationYears(): ?int
    {
        return match ($this) {
            self::Electricity => null,
            self::ColdWater => 6,
            self::HotWater => 5,
        };
    }

    public function format(int $value, bool $withUnit = false): string
    {
        $sign = $value < 0 ? '-' : '';
        $value = abs($value);
        $text = number_format(intdiv($value, $this->scale()), 0, ',', '.');

        if ($this->decimals() > 0) {
            $text .= ','.str_pad((string) ($value % $this->scale()), $this->decimals(), '0', STR_PAD_LEFT);
        }

        return $sign.$text.($withUnit ? ' '.$this->unit() : '');
    }

    /**
     * Eingabe in gespeicherte Ganzzahl umrechnen. Wasser: Komma oder Punkt als Dezimaltrennzeichen,
     * bis zu 4 Nachkommastellen (Rollenzählwerk), gespeichert wird auf volle Liter abgeschnitten.
     * Liefert null bei ungültiger Eingabe.
     */
    public function parse(string|int|float|null $input): ?int
    {
        $input = str_replace([' ', "\u{00A0}"], '', trim((string) $input));

        if (! $this->isWater()) {
            return preg_match('/^\d{1,15}$/', $input) === 1 ? (int) $input : null;
        }

        if (preg_match('/^(\d{1,12})(?:[.,](\d{1,4}))?$/', $input, $m) !== 1) {
            return null;
        }

        $fraction = substr(str_pad($m[2] ?? '', 3, '0'), 0, 3);

        return (int) $m[1] * 1000 + (int) $fraction;
    }

    /** Wert für ein Eingabefeld (ohne Tausenderpunkte). */
    public function input(int $value): string
    {
        return str_replace('.', '', $this->format($value));
    }

    /** @return list<self> */
    public static function water(): array
    {
        return [self::ColdWater, self::HotWater];
    }
}

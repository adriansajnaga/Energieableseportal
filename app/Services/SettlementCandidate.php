<?php

namespace App\Services;

use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Settlement;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Ein abrechenbares Paar aus Zähler und Mieter in einem Monat.
 */
final class SettlementCandidate
{
    public const SETTLED = 'settled';

    public const READY = 'ready';

    public const MISSING_START = 'missing_start';

    public const MISSING_READING = 'missing_reading';

    public const MISSING_PRICE = 'missing_price';

    /**
     * @param  Collection<int, Reading>  $samples  mögliche Ablesungen nach dem Anfangsstand
     */
    public function __construct(
        public readonly CarbonImmutable $period,
        public readonly Meter $meter,
        public readonly Tenant $tenant,
        public readonly CarbonImmutable $endsOn,
        public readonly ?Reading $startReading,
        public readonly Collection $samples,
        public readonly ?ElectricityPrice $price,
        public readonly ?Settlement $settlement,
    ) {}

    public function status(): string
    {
        return match (true) {
            $this->settlement !== null => self::SETTLED,
            $this->startReading === null => self::MISSING_START,
            $this->samples->isEmpty() => self::MISSING_READING,
            $this->price === null => self::MISSING_PRICE,
            default => self::READY,
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            self::SETTLED => __('Abgerechnet'),
            self::READY => __('Bereit'),
            self::MISSING_START => __('Kein Anfangsstand'),
            self::MISSING_READING => __('Keine Ablesung'),
            self::MISSING_PRICE => __('Kein Strompreis'),
        };
    }

    public function isReady(): bool
    {
        return $this->status() === self::READY;
    }

    /**
     * Standardauswahl: die Ablesung, die dem Periodenende am nächsten liegt
     * (bei gleichem Abstand die spätere).
     */
    public function defaultSample(): ?Reading
    {
        return $this->samples
            ->sortBy(fn (Reading $r) => [abs($r->read_on->diffInDays($this->endsOn)), -$r->read_on->timestamp])
            ->first();
    }

    public function key(): string
    {
        return $this->meter->id.'-'.$this->tenant->id;
    }
}

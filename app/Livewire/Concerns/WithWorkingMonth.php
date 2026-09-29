<?php

namespace App\Livewire\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Url;

/**
 * Monatsauswahl, die pro Benutzer gespeichert wird (Altsystem: SETTLEMENT_DATE).
 */
trait WithWorkingMonth
{
    #[Url(as: 'monat')]
    public string $month = '';

    public function mountWithWorkingMonth(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = Auth::user()->workingMonth()->format('Y-m');
        }
    }

    public function period(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month);
    }

    public function previousMonth(): void
    {
        $this->setMonth($this->period()->subMonthNoOverflow());
    }

    public function nextMonth(): void
    {
        $this->setMonth($this->period()->addMonthNoOverflow());
    }

    private function setMonth(CarbonImmutable $month): void
    {
        $this->month = $month->format('Y-m');
        Auth::user()->update(['working_month' => $month->toDateString()]);
    }
}

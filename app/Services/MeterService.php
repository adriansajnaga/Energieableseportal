<?php

namespace App\Services;

use App\Enums\ReadingSource;
use App\Models\Meter;
use App\Models\MeterAssignment;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mieterwechsel und Zählerwechsel. Beide Vorgänge erzeugen eine Zwischenablesung
 * am Stichtag, die als Endstand für den bisherigen und als Anfangsstand für den neuen
 * Zeitraum dient.
 */
class MeterService
{
    public function __construct(private ReadingService $readings) {}

    public function create(array $attributes, ?int $initialValue, ?CarbonInterface $startsOn, ?User $user): Meter
    {
        return DB::transaction(function () use ($attributes, $initialValue, $startsOn, $user) {
            $meter = Meter::create($attributes);

            if ($meter->tenant_id) {
                MeterAssignment::create([
                    'meter_id' => $meter->id,
                    'tenant_id' => $meter->tenant_id,
                    'starts_on' => ($startsOn ?? now()->startOfMonth())->toDateString(),
                ]);
            }

            if ($initialValue !== null && $startsOn) {
                $this->readings->record($meter, $initialValue, $startsOn, ReadingSource::Admin, user: $user, isBase: true);
            }

            return $meter;
        });
    }

    public function changeTenant(Meter $meter, ?Tenant $newTenant, CarbonInterface $date, int $value, ?User $user): void
    {
        if ($newTenant?->id === $meter->tenant_id) {
            throw new RuntimeException(__('Der Zähler ist diesem Mieter bereits zugeordnet.'));
        }

        DB::transaction(function () use ($meter, $newTenant, $date, $value, $user) {
            $this->closeAssignment($meter, $date);

            // Die Ablesung gehört noch zum alten Mieter (Endstand) und ist Anfangsstand des neuen.
            $this->readings->record($meter, $value, $date, ReadingSource::Admin, __('Mieterwechsel'), user: $user, isBase: true);

            if ($newTenant) {
                MeterAssignment::create([
                    'meter_id' => $meter->id,
                    'tenant_id' => $newTenant->id,
                    'starts_on' => $date->toDateString(),
                ]);
            }

            $meter->update(['tenant_id' => $newTenant?->id]);
        });
    }

    public function replace(Meter $old, string $newNumber, CarbonInterface $date, int $finalValue, int $initialValue, ?User $user): Meter
    {
        return DB::transaction(function () use ($old, $newNumber, $date, $finalValue, $initialValue, $user) {
            $this->readings->record($old, $finalValue, $date, ReadingSource::Admin, __('Zählerwechsel - Ausbau'), user: $user, isBase: true);
            $this->closeAssignment($old, $date);

            $new = $this->create([
                'number' => $newNumber,
                'location' => $old->location,
                'factor' => $old->factor,
                'is_main' => $old->is_main,
                'parent_id' => $old->parent_id,
                'tenant_id' => $old->tenant_id,
                'installed_on' => $date->toDateString(),
            ], $initialValue, $date, $user);

            if ($old->is_main) {
                $old->children()->update(['parent_id' => $new->id]);
            }

            $old->update([
                'is_active' => false,
                'removed_on' => $date->toDateString(),
                'replaced_by_id' => $new->id,
            ]);

            return $new;
        });
    }

    private function closeAssignment(Meter $meter, CarbonInterface $date): void
    {
        $current = $meter->assignments()->whereNull('ends_on')->latest('starts_on')->first();

        if (! $current) {
            return;
        }

        if ($current->starts_on->greaterThanOrEqualTo($date)) {
            throw new RuntimeException(__('Der Stichtag muss nach dem Beginn der aktuellen Zuordnung liegen.'));
        }

        $current->update(['ends_on' => $date->toDateString()]);
    }
}

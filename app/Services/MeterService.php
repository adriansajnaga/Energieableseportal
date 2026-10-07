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
            // refresh(): Standardwerte der Datenbank (z. B. factor) ins Modell laden.
            $meter = Meter::create($attributes)->refresh();

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

    public function replace(
        Meter $old,
        string $newNumber,
        CarbonInterface $date,
        int $finalValue,
        int $initialValue,
        ?User $user,
        ?int $calibrationYear = null,
    ): Meter {
        return DB::transaction(function () use ($old, $newNumber, $date, $finalValue, $initialValue, $user, $calibrationYear) {
            $old->refresh();
            $this->readings->record($old, $finalValue, $date, ReadingSource::Admin, __('Zählerwechsel - Ausbau'), user: $user, isBase: true);
            $this->closeAssignment($old, $date);

            $new = $this->create([
                'number' => $newNumber,
                'medium' => $old->medium,
                'location' => $old->location,
                'calibration_year' => $calibrationYear,
                'factor' => $old->factor,
                'is_main' => $old->is_main,
                'is_analyzer' => $old->is_analyzer,
                'parent_id' => $old->parent_id,
                'feed_id' => $old->feed_id,
                'tenant_id' => $old->tenant_id,
                'installed_on' => $date->toDateString(),
            ], $initialValue, $date, $user);

            if ($old->is_main) {
                $old->children()->update(['parent_id' => $new->id]);
            }

            // Leitungsschema: nachgeschaltete Zähler hängen ab jetzt am neuen Zähler.
            $old->fedMeters()->update(['feed_id' => $new->id]);

            $old->update([
                'is_active' => false,
                'removed_on' => $date->toDateString(),
                'replaced_by_id' => $new->id,
            ]);

            return $new;
        });
    }

    /**
     * Leitungsschema: vorgeschalteten Zähler setzen. Erlaubt sind der eigene Hauptzähler (= direkt, feed_id leer)
     * und andere Zähler desselben Hauptzählers, aber nicht der Zähler selbst oder ein nachgeschalteter (Kreis).
     */
    public function setFeed(Meter $meter, ?Meter $feed): void
    {
        if ($meter->is_main) {
            throw new RuntimeException(__('Ein Hauptzähler hat keinen vorgeschalteten Zähler.'));
        }

        if (! $feed || $feed->id === $meter->parent_id) {
            $meter->update(['feed_id' => null]);

            return;
        }

        if ($feed->id === $meter->id || $this->isDownstream($feed, $meter)) {
            throw new RuntimeException(__('Ein Zähler kann nicht hinter sich selbst oder einem nachgeschalteten Zähler hängen.'));
        }

        if ($feed->is_main || $this->mainMeterId($feed) !== $meter->parent_id || $feed->isWater() !== $meter->isWater()) {
            throw new RuntimeException(__('Der vorgeschaltete Zähler muss am selben Hauptzähler hängen.'));
        }

        $meter->update(['feed_id' => $feed->id]);
    }

    /** Liegt $candidate (über feed_id) hinter $meter? */
    public function isDownstream(Meter $candidate, Meter $meter): bool
    {
        $seen = [];
        $current = $candidate;

        while ($current && $current->feed_id && ! isset($seen[$current->id])) {
            if ($current->feed_id === $meter->id) {
                return true;
            }
            $seen[$current->id] = true;
            $current = Meter::find($current->feed_id);
        }

        return false;
    }

    private function mainMeterId(Meter $meter): ?int
    {
        return $meter->is_main ? $meter->id : $meter->parent_id;
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

<?php

namespace App\Services;

use App\Models\MeterAssignment;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Ende des Mietverhältnisses: Ab dem Folgetag erscheint der Mieter nicht mehr in der Abrechnung.
 * Die Zuordnungen zu den Zählern enden am Folgetag (ends_on ist exklusiv), nach Ablauf
 * werden die Zähler frei (Leerstand) und der Mieter inaktiv.
 */
class TenantService
{
    public function setActiveUntil(Tenant $tenant, ?CarbonInterface $until): void
    {
        $previous = $tenant->getOriginal('active_until') ? CarbonImmutable::parse($tenant->getOriginal('active_until')) : null;
        $until = $until ? CarbonImmutable::parse($until)->startOfDay() : null;

        if ($until && $tenant->active_from && $until->lessThan($tenant->active_from)) {
            throw new RuntimeException(__('Das Ende des Mietverhältnisses liegt vor dessen Beginn.'));
        }

        DB::transaction(function () use ($tenant, $previous, $until) {
            // Ein früher gesetztes Ende zurücknehmen.
            if ($previous) {
                $tenant->assignments()->whereDate('ends_on', $previous->addDay()->toDateString())->update(['ends_on' => null]);
                $tenant->assignments()->whereNull('ends_on')->with('meter')->get()
                    ->each(fn (MeterAssignment $a) => $a->meter->tenant_id ?? $a->meter->update(['tenant_id' => $tenant->id]));
            }

            if ($until) {
                $end = $until->addDay();

                $open = $tenant->assignments()->whereNull('ends_on')->with('meter')->get();

                foreach ($open as $assignment) {
                    if ($assignment->starts_on->greaterThanOrEqualTo($end)) {
                        throw new RuntimeException(__('Der Zähler :meter ist dem Mieter erst ab :date zugeordnet.', [
                            'meter' => $assignment->meter->number,
                            'date' => $assignment->starts_on->format('d.m.Y'),
                        ]));
                    }

                    $assignment->update(['ends_on' => $end->toDateString()]);

                    if ($end->lessThanOrEqualTo(today()) && $assignment->meter->tenant_id === $tenant->id) {
                        $assignment->meter->update(['tenant_id' => null]);
                    }
                }
            }

            $tenant->active_until = $until;

            if ($until && $until->lessThan(today())) {
                $tenant->is_active = false;
            } elseif (! $until && $previous) {
                $tenant->is_active = true;
            }

            $tenant->save();
        });
    }
}

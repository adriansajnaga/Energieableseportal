<?php

namespace Database\Seeders;

use App\Enums\ReadingSource;
use App\Enums\Role;
use App\Models\ElectricityPrice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MeterService;
use App\Services\ReadingService;
use App\Services\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demodaten für die lokale Entwicklung. Echte Daten kommen über "php artisan energie:import-legacy".
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::factory()->create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@example.com',
            'role' => Role::Admin,
        ]);

        User::factory()->create([
            'name' => 'Hausmeister',
            'username' => 'hausmeister',
            'email' => 'hausmeister@example.com',
            'role' => Role::Caretaker,
        ]);

        $meters = app(MeterService::class);
        $readings = app(ReadingService::class);
        $start = now()->subMonthsNoOverflow(6)->startOfMonth()->toImmutable();

        $main = $meters->create(['number' => 'HZ-0001', 'location' => 'Hauptverteilung', 'is_main' => true, 'factor' => 1], null, null, $admin);

        $tenants = Tenant::factory()->count(6)->create();

        foreach ($tenants as $i => $tenant) {
            $meter = $meters->create([
                'number' => sprintf('1ESY%08d', 11000000 + $i),
                'location' => 'Halle '.($i + 1),
                'factor' => $i === 0 ? 40 : 1,
                'parent_id' => $main->id,
                'tenant_id' => $tenant->id,
            ], 10000 + $i * 1000, $start, $admin);

            $value = 10000 + $i * 1000;
            $daily = [8, 15, 4, 22, 11, 6][$i];

            for ($m = 0; $m < 6; $m++) {
                $date = $start->addMonthsNoOverflow($m)->addDays(19 + $i % 5);
                $value += (int) round($daily * ($start->addMonthsNoOverflow($m)->diffInDays($date) + ($m ? 30 - 19 : 0)));

                if ($date->isFuture()) {
                    break;
                }

                $readings->record($meter, $value, $date, $m % 2 ? ReadingSource::TenantQr : ReadingSource::Caretaker, $tenant->name);
            }
        }

        for ($m = 0; $m < 7; $m++) {
            ElectricityPrice::create([
                'meter_id' => $main->id,
                'month' => $start->addMonthsNoOverflow($m)->toDateString(),
                'supplier_invoice_number' => 'SW-'.$start->addMonthsNoOverflow($m)->format('Ym'),
                'consumption_kwh' => 3200 + $m * 50,
                'net_amount' => round((3200 + $m * 50) * (0.27 + $m * 0.002), 2),
                'net_price' => 0.27 + $m * 0.002,
            ]);
        }

        // Die ersten Monate abrechnen, damit Berichte und Dashboard Daten zeigen.
        $settlements = app(SettlementService::class);
        for ($m = 0; $m < 4; $m++) {
            $settlements->settleAll(CarbonImmutable::parse($start->addMonthsNoOverflow($m)), true, $admin);
        }
    }
}

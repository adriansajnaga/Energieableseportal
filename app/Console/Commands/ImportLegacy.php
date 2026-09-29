<?php

namespace App\Console\Commands;

use App\Enums\ReadingSource;
use App\Enums\Role;
use App\Enums\SettlementType;
use App\Models\ActivityLog;
use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\MeterAssignment;
use App\Models\Reading;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Übernimmt die Daten aus der alten Datenbank iascomm_energie.
 *
 * IDs des Altsystems werden in legacy_id gespeichert, alte QR-Hashes in meters.legacy_hash.
 */
class ImportLegacy extends Command
{
    protected $signature = 'energie:import-legacy
        {--connection= : Datenbankverbindung der alten Datenbank (Standard: config energie.legacy_connection)}
        {--photos= : Pfad zum alten Ordner uploads/readings, um die Fotos zu übernehmen}
        {--fresh : Vorhandene Mieter, Zähler, Ablesungen, Preise und Abrechnungen vorher löschen}';

    protected $description = 'Importiert Mieter, Zähler, Ablesungen, Strompreise, Abrechnungen und Benutzer aus dem Altsystem';

    private ConnectionInterface $legacy;

    /** @var array<string, array<int, int>> Alte ID => neue ID je Tabelle */
    private array $map = ['users' => [], 'tenants' => [], 'meters' => [], 'readings' => []];

    public function handle(): int
    {
        $this->legacy = DB::connection($this->option('connection') ?: config('energie.legacy_connection'));

        if (Tenant::exists() || Meter::exists()) {
            if (! $this->option('fresh')) {
                $this->error('Es sind bereits Daten vorhanden. Mit --fresh werden sie vor dem Import gelöscht.');

                return self::FAILURE;
            }

            $this->wipe();
        }

        DB::transaction(function () {
            // Beim Import keine Protokolleinträge für jeden Datensatz schreiben.
            Model::withoutEvents(function () {
                $this->importUsers();
                $this->importTenants();
                $this->importMeters();
                $this->importReadings();
                $this->importAssignments();
                $this->importPrices();
                $this->importSettlements();
            });
        });

        $counter = (int) Settlement::max('invoice_number');
        Setting::put('invoice_counter', (string) $counter);

        $this->table(['Tabelle', 'Anzahl'], [
            ['Benutzer', count($this->map['users'])],
            ['Mieter', Tenant::count()],
            ['Zähler', Meter::count()],
            ['Zuordnungen', MeterAssignment::count()],
            ['Ablesungen', Reading::count()],
            ['Strompreise', ElectricityPrice::count()],
            ['Abrechnungen', Settlement::count()],
            ['Letzte Rechnungsnr.', $counter],
        ]);

        $this->info('Import abgeschlossen. Bitte Ergebnis stichprobenartig prüfen (Abrechnung, Zählerstände).');

        return self::SUCCESS;
    }

    private function importUsers(): void
    {
        foreach ($this->legacy->table('login')->get() as $row) {
            $row = $this->clean($row);
            $username = $row->LOGIN ?: 'user'.$row->ID;
            $email = filter_var($row->EMAIL, FILTER_VALIDATE_EMAIL) && ! User::where('email', $row->EMAIL)->exists()
                ? $row->EMAIL
                : Str::slug($username).'@legacy.invalid';

            $user = User::query()->updateOrCreate(['username' => $username], [
                'name' => trim($row->NAME1.' '.$row->NAME2) ?: $username,
                'email' => $email,
                // Altsystem speicherte Klartext-Passwörter; hier werden sie gehasht übernommen.
                'password' => Hash::make((string) $row->PASS ?: Str::random(32)),
                'role' => Role::Admin,
                'is_active' => (string) $row->ACTIV === '1',
                'working_month' => $this->date($row->SETTLEMENT_DATE),
                'email_verified_at' => now(),
                'legacy_id' => $row->ID,
            ]);

            $this->map['users'][$row->ID] = $user->id;
        }
    }

    private function importTenants(): void
    {
        foreach ($this->legacy->table('tenant')->orderBy('ID')->get() as $row) {
            $row = $this->clean($row);
            $tenant = Tenant::create([
                'name' => $row->NAME ?: 'Mieter '.$row->ID,
                'debtor_number' => (int) $row->DEBITOR ?: null,
                'street' => $row->STREET,
                'zip' => $row->ZIP,
                'city' => $row->CITY,
                'phone' => $row->TEL,
                'email' => filter_var($row->EMAIL, FILTER_VALIDATE_EMAIL) ? $row->EMAIL : null,
                'is_active' => (int) $row->ACT === 1,
                'active_from' => $this->date($row->DATE_ADD),
                'legacy_id' => $row->ID,
            ]);

            $this->map['tenants'][$row->ID] = $tenant->id;
        }
    }

    private function importMeters(): void
    {
        $rows = $this->legacy->table('meters')->orderBy('ID')->get()->map(fn ($r) => $this->clean($r));

        foreach ($rows as $row) {
            $meter = Meter::create([
                'number' => $row->NUMBER ?: 'Zähler '.$row->ID,
                'location' => $row->LOC,
                'factor' => max(1, (int) $row->FACTOR),
                'is_main' => $row->PARENT_METER_ID === 'PARENT',
                'tenant_id' => $this->map['tenants'][$row->TENANT_ID] ?? null,
                'is_active' => (int) $row->ACT === 1,
                // Ereignisse sind beim Import aus, daher Token hier setzen.
                'qr_token' => Meter::newQrToken(),
                'legacy_hash' => preg_match('/^[a-f0-9]{32}$/', (string) $row->HASH) ? $row->HASH : null,
                'legacy_id' => $row->ID,
            ]);

            $this->map['meters'][$row->ID] = $meter->id;
        }

        foreach ($rows as $row) {
            if (is_numeric($row->PARENT_METER_ID) && isset($this->map['meters'][(int) $row->PARENT_METER_ID])) {
                Meter::whereKey($this->map['meters'][$row->ID])->update(['parent_id' => $this->map['meters'][(int) $row->PARENT_METER_ID]]);
            }
        }
    }

    private function importReadings(): void
    {
        $photos = $this->option('photos');

        $this->legacy->table('readings')->orderBy('ID')->chunk(500, function (Collection $rows) use ($photos) {
            foreach ($rows as $row) {
                $row = $this->clean($row);
                $meterId = $this->map['meters'][$row->METER_ID] ?? null;

                if (! $meterId || ! $this->date($row->READING_DATE)) {
                    $this->warn("Ablesung {$row->ID} übersprungen (Zähler oder Datum fehlt).");

                    continue;
                }

                $reader = (string) $row->READER_NAME;
                $source = match (true) {
                    str_starts_with($reader, 'System') => ReadingSource::System,
                    str_starts_with($reader, 'qr_mieter') => ReadingSource::TenantQr,
                    str_starts_with($reader, 'qr_hm') => ReadingSource::Caretaker,
                    default => ReadingSource::Admin,
                };

                $reading = Reading::create([
                    'meter_id' => $meterId,
                    'tenant_id' => $this->map['tenants'][$row->TENANT_ID] ?? null,
                    'value' => max(0, (int) $row->VALUE),
                    'read_on' => $this->date($row->READING_DATE),
                    'is_base' => (int) $row->SETTLED === 1,
                    'source' => $source,
                    'reader_name' => preg_replace('/^qr_(mieter|hm_status|hm|x)_/', '', $reader) ?: null,
                    'photo_path' => $this->photo($row->PHOTO, $photos),
                    'status' => 'approved',
                    'legacy_id' => $row->ID,
                ]);

                $this->map['readings'][$row->ID] = $reading->id;
            }
        });
    }

    /**
     * Das Altsystem kannte nur den aktuellen Mieter je Zähler. Die Historie wird aus den
     * Ablesungen rekonstruiert: jeder Mieterwechsel in der Folge der Ablesungen beginnt einen Abschnitt.
     */
    private function importAssignments(): void
    {
        foreach (Meter::all() as $meter) {
            $segments = [];

            $readings = Reading::query()->where('meter_id', $meter->id)->whereNotNull('tenant_id')
                ->orderBy('read_on')->get(['tenant_id', 'read_on']);

            foreach ($readings as $reading) {
                $last = end($segments);

                if (! $last || $last['tenant_id'] !== $reading->tenant_id) {
                    $segments[] = ['tenant_id' => $reading->tenant_id, 'starts_on' => $reading->read_on->copy()->startOfMonth()];
                }
            }

            if ($meter->tenant_id && (! $segments || end($segments)['tenant_id'] !== $meter->tenant_id)) {
                $segments[] = [
                    'tenant_id' => $meter->tenant_id,
                    'starts_on' => ($meter->tenant->active_from ?? now())->copy()->startOfMonth(),
                ];
            }

            foreach ($segments as $i => $segment) {
                $next = $segments[$i + 1] ?? null;
                $isCurrent = ! $next && $segment['tenant_id'] === $meter->tenant_id;

                MeterAssignment::create([
                    'meter_id' => $meter->id,
                    'tenant_id' => $segment['tenant_id'],
                    'starts_on' => $segment['starts_on']->toDateString(),
                    'ends_on' => $next ? $next['starts_on']->toDateString()
                        : ($isCurrent ? null : $readings->where('tenant_id', $segment['tenant_id'])->max('read_on')?->toDateString()),
                ]);
            }
        }
    }

    private function importPrices(): void
    {
        foreach ($this->legacy->table('eprice')->orderBy('ID')->get() as $row) {
            $row = $this->clean($row);
            $meterId = $this->map['meters'][$row->METER_ID] ?? null;

            if (! $meterId || ! $this->date($row->MONTH)) {
                continue;
            }

            ElectricityPrice::updateOrCreate(
                ['meter_id' => $meterId, 'month' => CarbonImmutable::parse($row->MONTH)->startOfMonth()->toDateString()],
                [
                    'supplier_invoice_number' => $row->INVOICE_ID,
                    'consumption_kwh' => (float) $row->CONSUMPTION,
                    'net_amount' => round((float) $row->CHARGE, 2),
                    'net_price' => round((float) $row->NET_PRICE, 5),
                    'updated_by' => $this->map['users'][$row->EDITORS_ID] ?? null,
                    'legacy_id' => $row->ID,
                ],
            );
        }
    }

    private function importSettlements(): void
    {
        $vatRate = (float) Setting::get('vat_rate');

        foreach ($this->legacy->table('settlements')->orderBy('ID')->get() as $row) {
            $row = $this->clean($row);
            $meter = Meter::find($this->map['meters'][$row->METER_ID] ?? 0);
            $tenantId = $this->map['tenants'][$row->TENANT_ID] ?? null;

            if (! $meter || ! $tenantId) {
                $this->warn("Abrechnung {$row->ID} übersprungen (Zähler oder Mieter fehlt).");

                continue;
            }

            $start = Reading::find($this->map['readings'][$row->FIRST_READING_ID] ?? 0);
            $end = Reading::find($this->map['readings'][$row->SECOND_READING_ID] ?? 0);
            $period = CarbonImmutable::parse($row->SETTLEMENT_PERIOD)->startOfMonth();
            $net = round((float) $row->NET_CHARGE, 2);
            $vat = round($net * $vatRate / 100, 2);
            $mainMeterId = is_numeric($row->PARENT_METER_ID) ? ($this->map['meters'][(int) $row->PARENT_METER_ID] ?? null) : null;
            $basePrice = ElectricityPrice::where('meter_id', $mainMeterId)->whereDate('month', $period->toDateString())->value('net_price')
                ?? ((float) $row->PRICE_FACTOR > 0 ? (float) $row->NET_PRICE / (float) $row->PRICE_FACTOR : (float) $row->NET_PRICE);
            $isInvoiced = $row->STAT === 'INVOICED' && (int) $row->INVOICE_NR > 0;

            Settlement::create([
                'type' => SettlementType::Invoice,
                'invoice_number' => $isInvoiced ? (int) $row->INVOICE_NR : null,
                'invoice_date' => $isInvoiced ? $this->date($row->CREATE_DATE) : null,
                'period' => $period->toDateString(),
                'tenant_id' => $tenantId,
                'meter_id' => $meter->id,
                'main_meter_id' => $mainMeterId,
                'start_reading_id' => $start?->id,
                'end_reading_id' => $end?->id,
                'starts_on' => $start?->read_on?->toDateString() ?? $period->toDateString(),
                'ends_on' => $end?->read_on?->toDateString() ?? $period->addMonthNoOverflow()->toDateString(),
                'consumption_kwh' => (int) $row->ENERGY_CONSUMPTION_VALUE,
                'meter_factor' => $meter->factor,
                'billed_kwh' => (int) $row->ENERGY_CONSUMPTION_VALUE * $meter->factor,
                'base_price' => round((float) $basePrice, 5),
                'price_factor' => (float) $row->PRICE_FACTOR ?: 1,
                'unit_price' => round((float) $row->NET_PRICE, 2),
                'net_amount' => $net,
                'vat_rate' => $vatRate,
                'vat_amount' => $vat,
                'gross_amount' => $net + $vat,
                'is_invoiced' => $isInvoiced,
                'legacy_id' => $row->ID,
            ]);
        }
    }

    private function photo(?string $path, ?string $directory): ?string
    {
        if (! $path || ! $directory || $path === '0') {
            return null;
        }

        $source = rtrim($directory, '/\\').DIRECTORY_SEPARATOR.basename($path);

        if (! File::exists($source)) {
            return null;
        }

        $target = 'readings/legacy_'.basename($path);
        Storage::disk('local')->put($target, File::get($source));

        return $target;
    }

    /**
     * Das Altsystem schrieb UTF-8-Formulardaten über eine latin1-Verbindung in latin2-Tabellen.
     * Solche doppelt kodierten Texte ("MÃ¼ller") werden hier zurückverwandelt.
     */
    private function clean(object $row): object
    {
        foreach ($row as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (! mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-2');
            }

            // MySQL "latin1" ist Windows-1252 (z. B. "ß" -> "ÃŸ", "ü" -> "Ã¼").
            if (str_contains($value, 'Ã') || str_contains($value, 'Â') || str_contains($value, 'Å')) {
                $decoded = mb_convert_encoding($value, 'Windows-1252', 'UTF-8');
                if (mb_check_encoding($decoded, 'UTF-8') && mb_strlen($decoded) < mb_strlen($value)) {
                    $value = $decoded;
                }
            }

            $row->{$key} = trim($value);
        }

        return $row;
    }

    private function date(mixed $value): ?string
    {
        if (! $value || str_starts_with((string) $value, '0000')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function wipe(): void
    {
        Settlement::query()->update(['cancels_id' => null]);
        Settlement::query()->delete();
        ElectricityPrice::query()->delete();
        Reading::query()->delete();
        MeterAssignment::query()->delete();
        Meter::query()->update(['parent_id' => null, 'replaced_by_id' => null]);
        Meter::query()->delete();
        Tenant::query()->delete();
        ActivityLog::query()->delete();
    }
}

<?php

use App\Enums\ReadingSource;
use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SettlementCandidate;
use App\Services\SettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config(['database.connections.legacy_test' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    $schema = Schema::connection('legacy_test');

    // Struktur wie in iascomm_energie.sql
    $schema->create('login', function (Blueprint $t) {
        $t->integer('ID');
        $t->string('LOGIN')->nullable();
        $t->string('PASS')->nullable();
        $t->string('NAME1')->nullable();
        $t->string('NAME2')->nullable();
        $t->string('EMAIL')->nullable();
        $t->string('ACTIV')->nullable();
        $t->date('SETTLEMENT_DATE')->nullable();
    });
    $schema->create('tenant', function (Blueprint $t) {
        $t->integer('ID');
        $t->text('NAME');
        $t->integer('DEBITOR');
        $t->text('STREET');
        $t->text('ZIP');
        $t->text('CITY');
        $t->text('TEL');
        $t->text('EMAIL');
        $t->integer('ACT');
        $t->date('DATE_ADD');
    });
    $schema->create('meters', function (Blueprint $t) {
        $t->integer('ID');
        $t->text('NUMBER');
        $t->integer('FACTOR');
        $t->text('LOC');
        $t->integer('TENANT_ID');
        $t->integer('ACT');
        $t->integer('QRCODE');
        $t->text('PARENT_METER_ID');
        $t->string('HASH');
    });
    $schema->create('readings', function (Blueprint $t) {
        $t->integer('ID');
        $t->integer('METER_ID');
        $t->integer('TENANT_ID');
        $t->integer('VALUE');
        $t->date('READING_DATE');
        $t->integer('SETTLED');
        $t->text('READER_NAME');
        $t->text('PHOTO');
    });
    $schema->create('eprice', function (Blueprint $t) {
        $t->integer('ID');
        $t->integer('METER_ID');
        $t->date('MONTH');
        $t->double('CONSUMPTION');
        $t->float('CHARGE');
        $t->double('NET_PRICE');
        $t->text('INVOICE_ID');
        $t->integer('EDITORS_ID');
    });
    $schema->create('settlements', function (Blueprint $t) {
        $t->integer('ID');
        $t->integer('INVOICE_NR');
        $t->date('SETTLEMENT_PERIOD');
        $t->integer('TENANT_ID');
        $t->integer('METER_ID');
        $t->integer('PARENT_METER_ID');
        $t->integer('FIRST_READING_ID');
        $t->integer('SECOND_READING_ID');
        $t->integer('ENERGY_CONSUMPTION_VALUE');
        $t->float('PRICE_FACTOR');
        $t->float('NET_PRICE');
        $t->float('NET_CHARGE');
        $t->text('STAT');
        $t->timestamp('CREATE_DATE');
    });

    $db = DB::connection('legacy_test');
    $db->table('login')->insert(['ID' => 7, 'LOGIN' => 'adrian', 'PASS' => 'geheim123', 'NAME1' => 'Adrian', 'NAME2' => 'S.', 'EMAIL' => 'adrian@example.com', 'ACTIV' => '1', 'SETTLEMENT_DATE' => '2024-06-01']);
    // "Müller" doppelt kodiert, wie es das Altsystem gespeichert hat.
    $db->table('tenant')->insert(['ID' => 3, 'NAME' => 'MÃ¼ller GmbH', 'DEBITOR' => 10001, 'STREET' => 'WrangelstraÃŸe 1', 'ZIP' => '24539', 'CITY' => 'NeumÃ¼nster', 'TEL' => '', 'EMAIL' => '', 'ACT' => 1, 'DATE_ADD' => '2024-01-01']);
    $db->table('meters')->insert([
        ['ID' => 1, 'NUMBER' => 'HZ-1', 'FACTOR' => 1, 'LOC' => 'Haupt', 'TENANT_ID' => 0, 'ACT' => 1, 'QRCODE' => 1, 'PARENT_METER_ID' => 'PARENT', 'HASH' => md5('1')],
        ['ID' => 2, 'NUMBER' => 'Z-2', 'FACTOR' => 40, 'LOC' => 'Halle 2', 'TENANT_ID' => 3, 'ACT' => 1, 'QRCODE' => 1, 'PARENT_METER_ID' => '1', 'HASH' => md5('2')],
    ]);
    $db->table('readings')->insert([
        ['ID' => 10, 'METER_ID' => 2, 'TENANT_ID' => 3, 'VALUE' => 1000, 'READING_DATE' => '2024-06-01', 'SETTLED' => 1, 'READER_NAME' => 'Admin', 'PHOTO' => ''],
        ['ID' => 11, 'METER_ID' => 2, 'TENANT_ID' => 3, 'VALUE' => 1100, 'READING_DATE' => '2024-06-21', 'SETTLED' => 0, 'READER_NAME' => 'qr_mieter_Hans', 'PHOTO' => 'uploads/readings/2_x.jpg'],
        ['ID' => 12, 'METER_ID' => 2, 'TENANT_ID' => 3, 'VALUE' => 1150, 'READING_DATE' => '2024-07-01', 'SETTLED' => 1, 'READER_NAME' => 'System - calculated', 'PHOTO' => ''],
        ['ID' => 13, 'METER_ID' => 2, 'TENANT_ID' => 3, 'VALUE' => 1300, 'READING_DATE' => '2024-07-31', 'SETTLED' => 0, 'READER_NAME' => 'qr_hm_Klaus', 'PHOTO' => ''],
    ]);
    $db->table('eprice')->insert([
        ['ID' => 1, 'METER_ID' => 1, 'MONTH' => '2024-06-01', 'CONSUMPTION' => 9000, 'CHARGE' => 2250, 'NET_PRICE' => 0.25, 'INVOICE_ID' => 'SW-1', 'EDITORS_ID' => 7],
        ['ID' => 2, 'METER_ID' => 1, 'MONTH' => '2024-07-01', 'CONSUMPTION' => 9000, 'CHARGE' => 2700, 'NET_PRICE' => 0.30, 'INVOICE_ID' => 'SW-2', 'EDITORS_ID' => 7],
    ]);
    $db->table('settlements')->insert(['ID' => 1, 'INVOICE_NR' => 42, 'SETTLEMENT_PERIOD' => '2024-06-01', 'TENANT_ID' => 3, 'METER_ID' => 2, 'PARENT_METER_ID' => 1,
        'FIRST_READING_ID' => 10, 'SECOND_READING_ID' => 12, 'ENERGY_CONSUMPTION_VALUE' => 150, 'PRICE_FACTOR' => 1.1, 'NET_PRICE' => 0.28, 'NET_CHARGE' => 1680, 'STAT' => 'INVOICED', 'CREATE_DATE' => '2024-07-03 10:00:00']);
});

it('imports the legacy database', function () {
    $this->artisan('energie:import-legacy', ['--connection' => 'legacy_test'])->assertSuccessful();

    $user = User::where('username', 'adrian')->first();
    expect(Hash::check('geheim123', $user->password))->toBeTrue()
        ->and($user->isAdmin())->toBeTrue();

    $tenant = Tenant::sole();
    expect($tenant->name)->toBe('Müller GmbH')->and($tenant->street)->toBe('Wrangelstraße 1')->and($tenant->city)->toBe('Neumünster');

    $main = Meter::where('number', 'HZ-1')->first();
    $meter = Meter::where('number', 'Z-2')->first();
    expect($main->is_main)->toBeTrue()
        ->and($meter->parent_id)->toBe($main->id)
        ->and($meter->factor)->toBe(40)
        ->and($meter->legacy_hash)->toBe(md5('2'))
        ->and($meter->qr_token)->not->toBeEmpty()
        ->and($meter->assignments()->sole()->ends_on)->toBeNull();

    expect(Reading::where('legacy_id', 11)->first())
        ->source->toBe(ReadingSource::TenantQr)
        ->reader_name->toBe('Hans');
    expect(Reading::where('legacy_id', 12)->first()->source)->toBe(ReadingSource::System);
    expect(ElectricityPrice::count())->toBe(2);

    $settlement = Settlement::sole();
    expect($settlement->invoice_number)->toBe(42)
        ->and($settlement->formattedNumber())->toBe('E-0042')
        ->and($settlement->billed_kwh)->toBe(6000)
        ->and((string) $settlement->gross_amount)->toBe('1999.20');

    // Nach dem Import geht die Abrechnung im Folgemonat nahtlos weiter.
    $july = app(SettlementService::class)->candidates(CarbonImmutable::parse('2024-07-01'))->sole();
    expect($july->status())->toBe(SettlementCandidate::READY)
        ->and($july->startReading->value)->toBe(1150);

    $next = app(SettlementService::class)->settle($july, $july->defaultSample(), null, true, null);
    expect($next->invoice_number)->toBe(43);

    // Alte QR-Codes leiten weiter.
    $this->get('/reading.php?reader=tenant&meter='.md5('2'))->assertRedirect(route('public.reading', $meter->qr_token));
});

it('refuses to import twice without --fresh', function () {
    $this->artisan('energie:import-legacy', ['--connection' => 'legacy_test'])->assertSuccessful();
    $this->artisan('energie:import-legacy', ['--connection' => 'legacy_test'])->assertFailed();
    $this->artisan('energie:import-legacy', ['--connection' => 'legacy_test', '--fresh' => true])->assertSuccessful();

    expect(Tenant::count())->toBe(1)->and(Settlement::count())->toBe(1);
});

it('creates an administrator from the command line', function () {
    $this->artisan('energie:create-admin')
        ->expectsQuestion('Name', 'Adrian')
        ->expectsQuestion('Benutzername', 'adrian')
        ->expectsQuestion('E-Mail', 'adrian@example.com')
        ->expectsQuestion('Passwort (min. 8 Zeichen)', 'sehr-geheim-123')
        ->assertSuccessful();

    expect(User::where('username', 'adrian')->first())->isAdmin()->toBeTrue();
});

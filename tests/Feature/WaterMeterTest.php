<?php

use App\Enums\Medium;
use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Models\Meter;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MeterService;
use App\Services\ReadingService;
use App\Services\SettlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('username', 'admin')->first();
    $this->caretaker = User::where('username', 'hausmeister')->first();
    $this->tenant = Tenant::active()->first();

    // Kaltwasserzähler mit Anfangsstand 100,250 m³ am Anfang des Vormonats (im laufenden Monat noch nicht abgelesen).
    $this->water = app(MeterService::class)->create(
        ['number' => 'WZ-1001', 'medium' => Medium::ColdWater, 'location' => 'Halle 3', 'calibration_year' => 2020, 'tenant_id' => $this->tenant->id],
        100250,
        now()->subMonthNoOverflow()->startOfMonth(),
        $this->admin,
    );
});

function waterPhoto(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('wasser.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

it('converts water readings between m³ input and stored litres', function () {
    $water = Medium::ColdWater;

    expect($water->parse('123,456'))->toBe(123456)
        ->and($water->parse('123.4'))->toBe(123400)
        ->and($water->parse(' 7 '))->toBe(7000)
        ->and($water->parse('0,0019'))->toBe(1)
        ->and($water->parse('12,34567'))->toBeNull()
        ->and($water->parse('1.234,5'))->toBeNull()
        ->and($water->parse('-1'))->toBeNull()
        ->and($water->format(1234567, true))->toBe('1.234,567 m³')
        ->and($water->input(1234567))->toBe('1234,567')
        ->and(Medium::Electricity->parse('12,5'))->toBeNull()
        ->and(Medium::Electricity->parse('1250'))->toBe(1250)
        ->and(Medium::Electricity->format(1250, true))->toBe('1.250 kWh');
});

it('creates a water meter from the meter list with m³ start value', function () {
    Volt::actingAs($this->admin)->test('meters.index')
        ->call('create')
        ->set('number', 'WW-77')
        ->set('medium', 'hot_water')
        ->set('calibration_year', '2023')
        ->set('tenant_id', (string) $this->tenant->id)
        ->set('initial_value', '45,5')
        ->call('save')
        ->assertHasNoErrors();

    $meter = Meter::where('number', 'WW-77')->first();
    expect($meter->medium)->toBe(Medium::HotWater)
        ->and($meter->factor)->toBe(1)
        ->and($meter->calibrationValidUntil())->toBe(2028)
        ->and($meter->readings()->first()->value)->toBe(45500);

    Volt::actingAs($this->admin)->test('meters.index')
        ->call('create')
        ->set('number', 'WW-78')
        ->set('medium', 'cold_water')
        ->set('initial_value', '45,12345')
        ->set('starts_on', now()->toDateString())
        ->call('save')
        ->assertHasErrors(['initial_value']);
});

it('only allows switching between cold and hot water when editing', function () {
    Volt::actingAs($this->admin)->test('meters.index')
        ->call('edit', $this->water->id)
        ->set('medium', 'electricity')
        ->call('save')
        ->assertHasErrors(['medium']);

    Volt::actingAs($this->admin)->test('meters.index')
        ->call('edit', $this->water->id)
        ->set('medium', 'hot_water')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->water->fresh()->medium)->toBe(Medium::HotWater);
});

it('accepts a tenant water reading via QR code in m³', function () {
    Storage::fake('local');

    $this->get(route('public.reading', $this->water->qr_token))
        ->assertOk()
        ->assertSee('100,250 m³')
        ->assertSee('m³, mit allen Nachkommastellen');

    Volt::test('public.reading', ['token' => $this->water->qr_token])
        ->set('value', '104,5')
        ->set('reader_name', 'Max Mieter')
        ->set('photo', waterPhoto())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $reading = $this->water->readings()->latest('id')->first();
    expect($reading->value)->toBe(104500)
        ->and($reading->source)->toBe(ReadingSource::TenantQr);

    Volt::test('public.reading', ['token' => $this->water->qr_token])
        ->set('value', '104,5 m3')->set('reader_name', 'X')->set('photo', waterPhoto())
        ->call('save')
        ->assertHasErrors(['value']);
});

it('flags a decreasing water reading with the value in m³', function () {
    $reading = app(ReadingService::class)->record($this->water, 99000, CarbonImmutable::today(), ReadingSource::Caretaker, user: $this->caretaker);

    expect($reading->status)->toBe(ReadingStatus::Pending)
        ->and($reading->check_note)->toContain('100,250 m³');
});

it('records water readings from the reading status and the reading list', function () {
    Volt::actingAs($this->caretaker)->test('readings.status')
        ->set('type', 'water')
        ->assertSee('WZ-1001')
        ->call('open', $this->water->id)
        ->assertSee('100,250 m³')
        ->set('value', '101,075')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->water->latestReading()->value)->toBe(101075);

    $reading = $this->water->latestReading();
    Volt::actingAs($this->admin)->test('readings.index')
        ->call('edit', $reading->id)
        ->assertSet('value', '101,075')
        ->set('value', '101,2')
        ->call('save')
        ->assertHasNoErrors();

    expect($reading->fresh()->value)->toBe(101200);

    Volt::actingAs($this->admin)->test('readings.index')
        ->set('type', 'water')
        ->assertSee('101,200 m³');
});

it('keeps water meters out of electricity settlements, prices and reports', function () {
    app(ReadingService::class)->record($this->water, 101000, CarbonImmutable::today(), ReadingSource::Admin, user: $this->admin);

    $candidates = app(SettlementService::class)->candidates(now());
    expect($candidates->pluck('meter.id'))->not->toContain($this->water->id);

    $this->water->update(['is_main' => true]);
    $this->actingAs($this->admin)->get('/prices')->assertOk()->assertDontSee('WZ-1001');
    $this->actingAs($this->admin)->get('/reports')->assertOk()->assertDontSee('WZ-1001');
});

it('shows the water meter page with calibration year and without settlements', function () {
    $this->actingAs($this->admin)->get(route('meters.show', $this->water))
        ->assertOk()
        ->assertSee('Kaltwasser')
        ->assertSee('2020')
        ->assertSee('100,250')
        ->assertDontSee('Abrechnungen');

    // Eichfrist Kaltwasser 6 Jahre: Eichjahr 2018 ist ab 2025 abgelaufen.
    expect($this->water->fill(['calibration_year' => 2018])->calibrationExpired(2025))->toBeTrue()
        ->and($this->water->calibrationExpired(2024))->toBeFalse();
});

it('includes water meters in the QR list PDF', function () {
    $response = $this->actingAs($this->admin)->get(route('pdf.qr-list'));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

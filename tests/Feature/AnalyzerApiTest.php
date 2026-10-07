<?php

use App\Enums\ReadingSource;
use App\Models\AnalyzerDevice;
use App\Models\AnalyzerReading;
use App\Models\AnalyzerSlot;
use App\Models\Meter;
use App\Models\Reading;
use App\Models\User;
use App\Services\MeterService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Log;
use Livewire\Volt\Volt;

beforeEach(function () {
    [$this->device, $this->token] = AnalyzerDevice::register('analizator-1');
});

/** Paket im Format der Firmware. */
function analyzerPacket(array $readings, array $now = ['ts' => 1759442400, 'boot' => 12, 'up' => 86460], string $device = 'analizator-1'): array
{
    return [
        'device' => $device,
        'fw' => '2.3',
        'now' => $now,
        'names' => ['1' => 'Hala A', '2' => 'Hala B', '3' => 'Licznik 3', '4' => 'Licznik 4', '5' => 'Licznik 5', '6' => 'Licznik 6'],
        'readings' => $readings,
    ];
}

function analyzerReading(array $overrides = []): array
{
    return array_merge([
        'ts' => 1759356000, 'boot' => 12, 'up' => 60, 'reason' => 'start',
        'meters' => [
            ['slot' => 1, 'addr' => 1, 'model' => 'LE-03M CT', 'kwh' => 1284.53, 'p_kw' => 12.4],
            ['slot' => 2, 'addr' => 2, 'model' => 'LE-03M', 'kwh' => 962.1],
        ],
    ], $overrides);
}

function sendPacket($test, array $packet, ?string $token = null)
{
    return $test->withToken($token ?? $test->token)->postJson('/api/analyzer/readings', $packet);
}

/** Plan-Ablesung um 00:00 Ortszeit des Analysators. */
function localMidnight(string $date): int
{
    return CarbonImmutable::parse($date, config('energie.analyzer_timezone'))->startOfDay()->getTimestamp();
}

it('stores a valid packet', function () {
    sendPacket($this, analyzerPacket([analyzerReading()]))
        ->assertOk()
        ->assertExactJson(['ok' => true, 'saved' => 2, 'duplicates' => 0, 'skipped' => 0]);

    $row = AnalyzerReading::where('slot', 1)->first();
    expect(AnalyzerReading::count())->toBe(2)
        ->and($row->kwh)->toBe('1284.53')
        ->and($row->model)->toBe('LE-03M CT')
        ->and($row->meter_name)->toBe('Hala A')
        ->and($row->ts->getTimestamp())->toBe(1759356000)
        ->and($row->getRawOriginal('ts'))->toBe('2025-10-01 22:00:00')
        ->and($row->ts_reconstructed)->toBeFalse()
        ->and($row->needs_review)->toBeFalse();

    $this->device->refresh();
    expect($this->device->fw)->toBe('2.3')
        ->and($this->device->last_boot)->toBe(12)
        ->and($this->device->last_seen_at)->not->toBeNull()
        ->and(AnalyzerSlot::where('device_id', $this->device->id)->count())->toBe(6)
        ->and(AnalyzerSlot::where('slot', 2)->first())->name->toBe('Hala B')->addr->toBe(2)->model->toBe('LE-03M');
});

it('ignores the same packet sent twice', function () {
    $packet = analyzerPacket([analyzerReading()]);
    sendPacket($this, $packet)->assertOk();

    sendPacket($this, $packet)
        ->assertOk()
        ->assertJson(['ok' => true, 'saved' => 0, 'duplicates' => 2, 'skipped' => 0]);

    expect(AnalyzerReading::count())->toBe(2);
});

it('rejects a wrong or missing token with 401', function () {
    sendPacket($this, analyzerPacket([analyzerReading()]), 'wrong-token')->assertUnauthorized();
    $this->postJson('/api/analyzer/readings', analyzerPacket([analyzerReading()]))->assertUnauthorized();

    expect(AnalyzerReading::count())->toBe(0);
});

it('reconstructs the time from the current boot', function () {
    sendPacket($this, analyzerPacket([analyzerReading(['ts' => null, 'up' => 60])]))->assertOk();

    $row = AnalyzerReading::first();
    expect($row->ts->getTimestamp())->toBe(1759442400 - (86460 - 60))
        ->and($row->ts_reconstructed)->toBeTrue()
        ->and($row->needs_review)->toBeFalse();
});

it('uses the server time when the device clock is not set', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00', 'UTC'));

    sendPacket($this, analyzerPacket([analyzerReading(['ts' => null, 'up' => 60])], ['ts' => null, 'boot' => 12, 'up' => 120]))->assertOk();

    $row = AnalyzerReading::first();
    expect($row->getRawOriginal('ts'))->toBe('2026-10-07 09:59:00')
        ->and($row->ts_reconstructed)->toBeTrue();
});

it('keeps readings from an earlier boot without time for review', function () {
    sendPacket($this, analyzerPacket([analyzerReading(['ts' => null, 'boot' => 11, 'up' => 500])]))->assertOk();

    $row = AnalyzerReading::first();
    expect($row->ts)->toBeNull()
        ->and($row->boot)->toBe(11)
        ->and($row->up)->toBe(500)
        ->and($row->needs_review)->toBeTrue()
        ->and($row->ts_reconstructed)->toBeFalse();
});

it('skips and logs an invalid meter value but saves the rest', function () {
    Log::spy();

    $reading = analyzerReading();
    $reading['meters'][0]['kwh'] = 'abc';

    sendPacket($this, analyzerPacket([$reading]))
        ->assertOk()
        ->assertJson(['ok' => true, 'saved' => 1, 'duplicates' => 0, 'skipped' => 1]);

    expect(AnalyzerReading::pluck('slot')->all())->toBe([2]);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => str_contains($message, 'ungültiger Zählerwert') && $context['meter']['kwh'] === 'abc');
});

it('accepts an empty meter list', function () {
    sendPacket($this, analyzerPacket([analyzerReading(['meters' => []])]))
        ->assertOk()
        ->assertJson(['saved' => 0, 'duplicates' => 0, 'skipped' => 0]);

    expect(AnalyzerReading::count())->toBe(0);
});

it('stores readings without model as null', function () {
    sendPacket($this, analyzerPacket([analyzerReading(['meters' => [['slot' => 3, 'addr' => 7, 'kwh' => 10]]])]))->assertOk();

    expect(AnalyzerReading::first())->model->toBeNull()->kwh->toBe('10.00');
});

it('answers 422 only for a body that is not JSON or has no readings', function () {
    $this->call('POST', '/api/analyzer/readings', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token], 'not json')
        ->assertStatus(422);
    sendPacket($this, ['device' => 'analizator-1'])->assertStatus(422);

    // Ungültige einzelne Ablesungen führen nie zu einem Fehler für das ganze Paket.
    sendPacket($this, analyzerPacket(['kaputt', analyzerReading(['reason' => 'unbekannt']), analyzerReading(['up' => 99])]))
        ->assertOk()
        ->assertJson(['saved' => 2, 'skipped' => 3]);
});

it('logs a device name mismatch and saves under the token device', function () {
    Log::spy();

    sendPacket($this, analyzerPacket([analyzerReading()], device: 'anderer-name'))->assertOk();

    expect(AnalyzerReading::where('device_id', $this->device->id)->count())->toBe(2);
    Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['payload_device'] === 'anderer-name');
});

it('calculates daily consumption and reports a drop as gap', function () {
    $this->seed(DatabaseSeeder::class);
    $packet = analyzerPacket(collect([['2026-10-01', 100.0], ['2026-10-02', 112.5], ['2026-10-03', 130.0], ['2026-10-04', 5.0]])
        ->map(fn ($p, $i) => ['ts' => localMidnight($p[0]), 'boot' => 3, 'up' => 1000 + $i, 'reason' => 'plan',
            'meters' => [['slot' => 1, 'addr' => 1, 'model' => 'LE-03M CT', 'kwh' => $p[1]]]])->all(), ['ts' => localMidnight('2026-10-04'), 'boot' => 3, 'up' => 2000]);
    sendPacket($this, $packet)->assertOk();

    $response = $this->actingAs(User::where('username', 'admin')->first())
        ->getJson(route('analyzer.api.consumption', [$this->device, 'slot' => 1, 'from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'day']))
        ->assertOk();

    expect($response->json('rows'))->toHaveCount(2)
        ->and($response->json('rows.0'))->toMatchArray(['period' => '2026-10-01', 'kwh' => 12.5, 'partial' => false])
        ->and($response->json('rows.1'))->toMatchArray(['period' => '2026-10-02', 'kwh' => 17.5])
        ->and($response->json('totals.0.kwh'))->toEqual(30)
        ->and($response->json('gaps'))->toHaveCount(1)
        ->and($response->json('gaps.0'))->toMatchArray(['slot' => 1, 'from_kwh' => 130, 'to_kwh' => 5, 'reason' => 'drop']);

    $month = $this->getJson(route('analyzer.api.consumption', [$this->device, 'from' => '2026-10-01', 'to' => '2026-10-31', 'group' => 'month']));
    expect($month->json('rows.0'))->toMatchArray(['period' => '2026-10', 'kwh' => 30]);
});

it('treats a changed Modbus address as discontinuity', function () {
    $this->seed(DatabaseSeeder::class);
    sendPacket($this, analyzerPacket([
        ['ts' => localMidnight('2026-10-01'), 'boot' => 1, 'up' => 1, 'reason' => 'plan', 'meters' => [['slot' => 1, 'addr' => 1, 'kwh' => 100]]],
        ['ts' => localMidnight('2026-10-02'), 'boot' => 1, 'up' => 2, 'reason' => 'plan', 'meters' => [['slot' => 1, 'addr' => 4, 'kwh' => 150]]],
    ]))->assertOk();

    $response = $this->actingAs(User::where('username', 'admin')->first())
        ->getJson(route('analyzer.api.consumption', [$this->device, 'from' => '2026-10-01', 'to' => '2026-10-02']));

    expect($response->json('rows'))->toBe([])->and($response->json('gaps.0.reason'))->toBe('addr');
});

it('lists devices, filters readings and exports CSV for logged-in users only', function () {
    $this->seed(DatabaseSeeder::class);
    sendPacket($this, analyzerPacket([analyzerReading(), analyzerReading(['up' => 61, 'reason' => 'reczny', 'ts' => 1759357000])]))->assertOk();

    $this->getJson(route('analyzer.api.devices'))->assertUnauthorized();

    $admin = User::where('username', 'admin')->first();
    $this->actingAs($admin)->getJson(route('analyzer.api.devices'))
        ->assertOk()
        ->assertJsonPath('data.0.name', 'analizator-1')
        ->assertJsonPath('data.0.readings_count', 4)
        ->assertJsonPath('data.0.slots.0.name', 'Hala A')
        ->assertJsonPath('data.0.slots.0.last_kwh', 1284.53);

    $this->actingAs($admin)->getJson(route('analyzer.api.readings', [$this->device, 'slot' => 2, 'reason' => 'reczny']))
        ->assertOk()
        ->assertJsonPath('total', 1)
        ->assertJsonPath('data.0.slot', 2)
        ->assertJsonPath('data.0.ts_local', '2025-10-02T00:16:40+02:00');

    $csv = $this->actingAs($admin)->get(route('analyzer.api.export', [$this->device, 'from' => '2025-10-01', 'to' => '2025-10-31']));
    $csv->assertOk();
    $content = $csv->streamedContent();
    expect($content)->toStartWith("\xEF\xBB\xBF")
        ->and($content)->toContain('Platz;Name;Adresse')
        ->and($content)->toContain('1;"Hala A";1;"LE-03M CT";"02.10.2025 00:00:00";nein;nein;start;1284,53');
});

it('creates a device with the artisan command and shows the token once', function () {
    $this->artisan('analyzer:create', ['name' => 'analizator-2'])
        ->expectsOutputToContain('Token:')
        ->expectsOutputToContain('/api/analyzer/readings')
        ->assertSuccessful();

    $device = AnalyzerDevice::where('name', 'analizator-2')->first();
    expect($device->token_hash)->toHaveLength(64);

    $this->artisan('analyzer:create', ['name' => 'analizator-2'])->assertFailed();
});

it('takes the earliest value of each day as reading of the assigned measuring point', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('username', 'admin')->first();
    $main = Meter::where('is_main', true)->first();
    $point = app(MeterService::class)->create(
        ['number' => 'AN-1', 'location' => 'Abzweig Halle 1-3', 'is_analyzer' => true, 'parent_id' => $main->id, 'installed_on' => '2026-09-01'],
        1000, CarbonImmutable::parse('2026-09-01'), $admin,
    );
    sendPacket($this, analyzerPacket([analyzerReading()]))->assertOk();
    AnalyzerSlot::where('device_id', $this->device->id)->where('slot', 1)
        ->update(['meter_id' => $point->id, 'meter_since' => '2026-09-30 22:00:00']);

    sendPacket($this, analyzerPacket([
        ['ts' => localMidnight('2026-10-01') + 36000, 'boot' => 5, 'up' => 2, 'reason' => 'reczny', 'meters' => [['slot' => 1, 'addr' => 1, 'kwh' => 1250.9]]],
        ['ts' => localMidnight('2026-10-01'), 'boot' => 5, 'up' => 1, 'reason' => 'plan', 'meters' => [['slot' => 1, 'addr' => 1, 'kwh' => 1240.7], ['slot' => 2, 'addr' => 2, 'kwh' => 10]]],
    ]))->assertOk();

    $readings = Reading::where('meter_id', $point->id)->where('source', ReadingSource::Analyzer)->get();
    expect($readings)->toHaveCount(1)
        ->and($readings->first()->read_on->toDateString())->toBe('2026-10-01')
        ->and($readings->first()->value)->toBe(1240)
        ->and($readings->first()->reader_name)->toContain('analizator-1');
});

it('assigns a slot to a measuring point in the interface and takes over earlier values', function () {
    $this->seed(DatabaseSeeder::class);
    $admin = User::where('username', 'admin')->first();
    $point = app(MeterService::class)->create(
        ['number' => 'AN-1', 'location' => 'Abzweig Halle 1-3', 'is_analyzer' => true, 'parent_id' => Meter::where('is_main', true)->value('id'), 'installed_on' => '2026-09-01'],
        1000, CarbonImmutable::parse('2026-09-01'), $admin,
    );
    sendPacket($this, analyzerPacket([
        ['ts' => localMidnight('2026-10-01'), 'boot' => 5, 'up' => 1, 'reason' => 'plan', 'meters' => [['slot' => 1, 'addr' => 1, 'kwh' => 1240.7]]],
        ['ts' => localMidnight('2026-10-02'), 'boot' => 5, 'up' => 2, 'reason' => 'plan', 'meters' => [['slot' => 1, 'addr' => 1, 'kwh' => 1260.2]]],
    ]))->assertOk();
    $slot = AnalyzerSlot::where('device_id', $this->device->id)->where('slot', 1)->first();

    Volt::actingAs($admin)->test('analyzer.devices')
        ->assertSee('Hala A')
        ->call('editSlot', $slot->id)
        ->set('meter_id', (string) $point->id)
        ->set('since', '2026-10-01')
        ->call('saveSlot')
        ->assertHasNoErrors()
        ->assertSee('AN-1');

    expect(Reading::where('meter_id', $point->id)->where('source', ReadingSource::Analyzer)->orderBy('read_on')->pluck('value')->all())->toBe([1240, 1260])
        ->and($slot->fresh()->meter_id)->toBe($point->id);
});

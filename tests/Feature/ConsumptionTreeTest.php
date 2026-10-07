<?php

use App\Enums\ReadingSource;
use App\Models\Meter;
use App\Models\Settlement;
use App\Models\User;
use App\Services\ConsumptionTree;
use App\Services\MeterService;
use App\Services\ReadingService;
use App\Services\SettlementService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Livewire\Volt\Volt;

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('username', 'admin')->first();
    $this->main = Meter::where('is_main', true)->first();
    // Abgerechneter Monat aus den Demodaten (die ersten vier Monate sind abgerechnet).
    $this->month = now()->subMonthsNoOverflow(4)->startOfMonth()->toImmutable();
    $this->tenantMeters = Meter::where('is_main', false)->orderBy('number')->get();

    // Messpunkt des Analysators auf dem Abzweig zu den ersten drei Hallen.
    $this->point = app(MeterService::class)->create(
        ['number' => 'AN-1', 'location' => 'Abzweig Halle 1-3', 'is_analyzer' => true, 'parent_id' => $this->main->id, 'installed_on' => $this->month->toDateString()],
        50000, $this->month, $this->admin,
    );
    foreach ($this->tenantMeters->take(3) as $meter) {
        app(MeterService::class)->setFeed($meter, $this->point);
    }
});

function settledKwh(Meter $meter, CarbonImmutable $month): int
{
    return (int) Settlement::effective()->where('meter_id', $meter->id)->whereDate('period', $month->toDateString())->sum('billed_kwh');
}

it('builds the consumption tree with the loss on the branch', function () {
    $branch = $this->tenantMeters->take(3)->sum(fn (Meter $m) => settledKwh($m, $this->month));
    app(ReadingService::class)->record($this->point, 50000 + $branch + 120, $this->month->addMonthNoOverflow(), ReadingSource::Admin);

    $roots = app(ConsumptionTree::class)->build($this->month);
    $root = $roots->first();
    $node = $root['children']->firstWhere('key', 'm'.$this->point->id);

    expect($roots)->toHaveCount(1)
        ->and($root['meter']->id)->toBe($this->main->id)
        ->and($root['source'])->toBe(ConsumptionTree::INVOICE)
        ->and($root['children'])->toHaveCount(4)
        ->and($node['kwh'])->toBe($branch + 120)
        ->and($node['source'])->toBe('measured')
        ->and($node['children'])->toHaveCount(3)
        ->and($node['children']->pluck('source')->unique()->all())->toBe(['settled'])
        ->and($node['children_kwh'])->toBe($branch)
        ->and($node['difference'])->toBe(120)
        ->and($root['children_kwh'])->toBe($branch + 120 + $this->tenantMeters->slice(3)->sum(fn (Meter $m) => settledKwh($m, $this->month)));
});

it('interpolates the value on the 1st between two readings and applies the meter factor', function () {
    $point = $this->point;
    $point->update(['factor' => 10]);
    app(ReadingService::class)->record($point, 50300, $this->month->addMonthNoOverflow()->addDays(9), ReadingSource::Admin);

    // 1. des Folgemonats liegt zwischen Anfangsstand und Ablesung am 10.: linear interpoliert.
    $days = $this->month->diffInDays($this->month->addMonthNoOverflow()->addDays(9));
    $expected = (int) round(300 * ($days - 9) / $days * 10);

    $node = app(ConsumptionTree::class)->build($this->month)->first()['children']->firstWhere('key', 'm'.$point->id);

    expect($node['kwh'])->toBe($expected)->and($node['source'])->toBe('interpolated');
});

it('prevents loops in the wiring schema', function () {
    $behind = $this->tenantMeters->first();

    expect(fn () => app(MeterService::class)->setFeed($this->point, $behind))->toThrow(RuntimeException::class)
        ->and(fn () => app(MeterService::class)->setFeed($this->point, $this->point))->toThrow(RuntimeException::class);

    app(MeterService::class)->setFeed($behind, $this->main);
    expect($behind->fresh()->feed_id)->toBeNull();
});

it('never settles measuring points', function () {
    $this->point->update(['tenant_id' => $this->tenantMeters->first()->tenant_id]);
    \App\Models\MeterAssignment::create(['meter_id' => $this->point->id, 'tenant_id' => $this->point->tenant_id, 'starts_on' => $this->month->toDateString()]);

    $candidates = app(SettlementService::class)->candidates($this->month->addMonthNoOverflow());

    expect($candidates->pluck('meter.id'))->not->toContain($this->point->id);
});

it('moves the meters behind a replaced measuring point to the new one', function () {
    $new = app(MeterService::class)->replace($this->point, 'AN-2', now()->toImmutable(), 50100, 0, $this->admin);

    expect($new->is_analyzer)->toBeTrue()
        ->and(Meter::where('feed_id', $new->id)->count())->toBe(3)
        ->and(Meter::where('feed_id', $this->point->id)->count())->toBe(0);
});

it('changes the wiring and creates measuring points on the schema page', function () {
    $meter = $this->tenantMeters->last();

    Volt::actingAs($this->admin)->test('analyzer.schema')
        ->assertSee('AN-1')
        ->set("feeds.{$meter->id}", (string) $this->point->id)
        ->assertHasNoErrors();
    expect($meter->fresh()->feed_id)->toBe($this->point->id);

    Volt::actingAs($this->admin)->test('analyzer.schema')
        ->call('createMeasuringPoint')
        ->set('number', 'AN-2')
        ->set('location', 'Abzweig Halle 4-6')
        ->set('parent_id', (string) $this->main->id)
        ->set('feed_id', (string) $this->point->id)
        ->set('initial_value', '1200')
        ->call('saveMeasuringPoint')
        ->assertHasNoErrors();

    $new = Meter::where('number', 'AN-2')->first();
    expect($new->is_analyzer)->toBeTrue()->and($new->tenant_id)->toBeNull()->and($new->feed_id)->toBe($this->point->id);

    Volt::actingAs(User::where('username', 'hausmeister')->first())->test('analyzer.schema')
        ->set("feeds.{$meter->id}", (string) $this->main->id)
        ->assertForbidden();
});

it('renders the analyzer pages and the distribution from the settlements', function () {
    $this->actingAs($this->admin)->get(route('analyzer.schema'))->assertOk()->assertSee('Leitungsschema');
    $this->actingAs($this->admin)->get(route('analyzer.devices'))->assertOk()->assertSee('/api/analyzer/readings');
    $this->actingAs($this->admin)->get(route('settlements.index', ['monat' => $this->month->format('Y-m')]))
        ->assertOk()->assertSee(route('settlements.distribution', ['monat' => $this->month->format('Y-m')]), false);
    $this->actingAs($this->admin)->get(route('settlements.distribution', ['monat' => $this->month->format('Y-m')]))
        ->assertOk()->assertSee('AN-1')->assertSee('Differenz');

    $caretaker = User::where('username', 'hausmeister')->first();
    $this->actingAs($caretaker)->get(route('analyzer.schema'))->assertOk();
    $this->actingAs($caretaker)->get(route('settlements.distribution'))->assertForbidden();
});

it('creates an analyzer device in the interface and shows the token once', function () {
    Volt::actingAs($this->admin)->test('analyzer.devices')
        ->call('createDevice')
        ->set('name', 'analizator-9')
        ->call('saveDevice')
        ->assertHasNoErrors()
        ->assertSet('shownDevice', 'analizator-9')
        ->assertSee('analizator-9');

    expect(\App\Models\AnalyzerDevice::where('name', 'analizator-9')->exists())->toBeTrue();
});

it('shows a measuring point installed later in earlier months and sums the meters behind it', function () {
    $later = app(MeterService::class)->create(
        ['number' => 'AN-9', 'location' => 'Abzweig Halle 4-6', 'is_analyzer' => true, 'parent_id' => $this->main->id, 'installed_on' => now()->toDateString()],
        100, now()->toImmutable(), $this->admin,
    );
    foreach ($this->tenantMeters->slice(3) as $meter) {
        app(MeterService::class)->setFeed($meter, $later);
    }

    $root = app(ConsumptionTree::class)->build($this->month)->first();
    $node = $root['children']->firstWhere('key', 'm'.$later->id);
    $behind = $this->tenantMeters->slice(3)->sum(fn (Meter $m) => settledKwh($m, $this->month));

    expect($root['children']->pluck('key')->take(2)->all())->toContain('m'.$later->id)
        ->and($node['not_installed'])->toBeTrue()
        ->and($node['kwh'])->toBeNull()
        ->and($node['children'])->toHaveCount(3)
        ->and($node['difference'])->toBeNull()
        ->and($node['effective_kwh'])->toBe($behind);

    $this->actingAs($this->admin)->get(route('settlements.distribution', ['monat' => $this->month->format('Y-m')]))
        ->assertOk()->assertSee('AN-9')->assertSee('eingebaut erst');
});

it('takes the main meter consumption only from the supplier invoice', function () {
    app(ReadingService::class)->record($this->main, 1000, $this->month, ReadingSource::Admin);
    app(ReadingService::class)->record($this->main, 99000, $this->month->addMonthNoOverflow(), ReadingSource::Admin);

    $invoice = (int) round((float) \App\Models\ElectricityPrice::where('meter_id', $this->main->id)->whereDate('month', $this->month->toDateString())->value('consumption_kwh'));
    $root = app(ConsumptionTree::class)->build($this->month)->first();
    expect($root['kwh'])->toBe($invoice)->and($root['source'])->toBe(ConsumptionTree::INVOICE);

    \App\Models\ElectricityPrice::where('meter_id', $this->main->id)->whereDate('month', $this->month->toDateString())->delete();
    $root = app(ConsumptionTree::class)->build($this->month)->first();
    expect($root['kwh'])->toBeNull()->and($root['source'])->toBe(ConsumptionTree::NO_INVOICE)->and($root['difference'])->toBeNull();
});

it('does not compare a measuring point installed in the middle of the month', function () {
    $this->point->update(['installed_on' => $this->month->addDays(10)->toDateString()]);
    app(ReadingService::class)->record($this->point, 50300, $this->month->addMonthNoOverflow(), ReadingSource::Admin);

    $node = app(ConsumptionTree::class)->build($this->month)->first()['children']->firstWhere('key', 'm'.$this->point->id);

    expect($node['partial'])->toBeTrue()
        ->and($node['difference'])->toBeNull()
        ->and($node['effective_kwh'])->toBe($node['children_kwh']);
});

it('lets the admin attach a measuring point without main meter on the schema page', function () {
    $loose = Meter::create(['number' => 'AN-LOOSE', 'location' => 'Abzweig Werkstatt', 'is_analyzer' => true]);

    Volt::actingAs($this->admin)->test('analyzer.schema')
        ->assertSee('Zähler ohne Hauptzähler')
        ->assertSee('AN-LOOSE')
        ->set("feeds.{$loose->id}", (string) $this->main->id)
        ->assertDontSee('Zähler ohne Hauptzähler');

    expect($loose->fresh()->parent_id)->toBe($this->main->id);

    Volt::actingAs($this->admin)->test('meters.index')
        ->call('create')
        ->set('number', 'AN-NEW')
        ->set('is_analyzer', true)
        ->assertSet('parent_id', (string) $this->main->id)
        ->set('parent_id', '')
        ->set('initial_value', '0')
        ->call('save')
        ->assertHasErrors(['parent_id']);
});

it('deletes a measuring point and moves the meters behind it up', function () {
    $behind = $this->tenantMeters->take(3)->pluck('id');
    $slotDevice = \App\Models\AnalyzerDevice::register('analizator-1')[0];
    $slot = \App\Models\AnalyzerSlot::create(['device_id' => $slotDevice->id, 'slot' => 1, 'meter_id' => $this->point->id]);

    Volt::actingAs($this->admin)->test('analyzer.schema')
        ->call('deleteMeasuringPoint', $this->point->id)
        ->assertHasNoErrors();

    expect(Meter::find($this->point->id))->toBeNull()
        ->and(Meter::whereIn('id', $behind)->pluck('feed_id')->unique()->all())->toBe([null])
        ->and($slot->fresh()->meter_id)->toBeNull()
        ->and(\App\Models\Reading::where('meter_id', $this->point->id)->count())->toBe(0);

    // Abrechnungszähler lassen sich so nicht löschen.
    expect(fn () => app(MeterService::class)->deleteMeasuringPoint($this->tenantMeters->first()))->toThrow(RuntimeException::class);

    Volt::actingAs(User::where('username', 'hausmeister')->first())->test('analyzer.schema')
        ->call('deleteMeasuringPoint', $this->tenantMeters->first()->id)
        ->assertForbidden();
});

it('deletes an analyzer device with its raw data', function () {
    [$device, $token] = \App\Models\AnalyzerDevice::register('analizator-2');
    \App\Models\AnalyzerSlot::create(['device_id' => $device->id, 'slot' => 1]);
    \App\Models\AnalyzerReading::create(['device_id' => $device->id, 'slot' => 1, 'addr' => 1, 'boot' => 1, 'up' => 1, 'reason' => 'plan', 'kwh' => 1, 'received_at' => now()]);

    Volt::actingAs($this->admin)->test('analyzer.devices')
        ->assertSee('analizator-2')
        ->call('deleteDevice', $device->id)
        ->assertDontSee('analizator-2');

    expect(\App\Models\AnalyzerDevice::count())->toBe(0)
        ->and(\App\Models\AnalyzerReading::count())->toBe(0)
        ->and(\App\Models\AnalyzerSlot::count())->toBe(0);
});

it('uploads, shows and deletes a floor plan', function () {
    Illuminate\Support\Facades\Storage::fake('local');
    $pdf = Illuminate\Http\UploadedFile::fake()->createWithContent('Rzut Halle.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

    Volt::actingAs($this->admin)->test('analyzer.schema')
        ->set('planFile', $pdf)
        ->call('uploadPlan')
        ->assertHasNoErrors()
        ->assertSee('Rzut Halle');

    $plan = \App\Models\SitePlan::first();
    expect($plan->title)->toBe('Rzut Halle')->and($plan->isPdf())->toBeTrue();
    Illuminate\Support\Facades\Storage::disk('local')->assertExists($plan->path);

    $this->actingAs($this->admin)->get($plan->url())->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs(User::where('username', 'hausmeister')->first())->get(route('analyzer.schema'))->assertOk()->assertSee($plan->url(), false);

    Volt::actingAs($this->admin)->test('analyzer.schema')
        ->set('planFile', Illuminate\Http\UploadedFile::fake()->createWithContent('plan.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>'))
        ->call('uploadPlan')
        ->assertHasErrors(['planFile']);

    Volt::actingAs($this->admin)->test('analyzer.schema')->call('deletePlan', $plan->id);
    expect(\App\Models\SitePlan::count())->toBe(0);
    Illuminate\Support\Facades\Storage::disk('local')->assertMissing($plan->path);

    auth()->logout();
    $this->get(route('analyzer.plans.show', 1))->assertRedirect();
});

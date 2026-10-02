<?php

use App\Enums\ReadingSource;
use App\Enums\ReadingStatus;
use App\Enums\Role;
use App\Mail\InvoiceMail;
use App\Models\ElectricityPrice;
use App\Models\Meter;
use App\Models\Setting;
use App\Models\Settlement;
use App\Models\Tenant;
use App\Models\User;
use App\Support\MailSettings;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;

// 1x1 PNG, damit die Tests ohne GD-Erweiterung laufen.
function fakePhoto(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('zaehler.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
    $this->admin = User::where('username', 'admin')->first();
    $this->caretaker = User::where('username', 'hausmeister')->first();
});

it('renders every page for an admin', function (string $url) {
    $this->actingAs($this->admin)->get($url)->assertOk();
})->with([
    '/dashboard', '/tenants', '/meters', '/meters/2', '/readings', '/readings?status=pending', '/readings/status',
    '/settlements', '/prices', '/reports', '/admin/users', '/admin/settings', '/admin/activity', '/settings/profile',
]);

it('renders the settlement form for a ready meter', function () {
    $settlement = Settlement::first();
    $settlement->update(['cancelled_at' => now()]);

    $this->actingAs($this->admin)
        ->get(route('settlements.create', [$settlement->meter_id, $settlement->tenant_id, 'monat' => $settlement->period->format('Y-m')]))
        ->assertOk()
        ->assertSee('Anfangsstand');
});

it('returns every PDF', function (string $route, array $params) {
    $response = $this->actingAs($this->admin)->get(route($route, $params));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF');
})->with([
    ['pdf.invoice', fn () => [Settlement::first()]],
    ['pdf.invoices', fn () => [Settlement::first()->period->format('Y-m')]],
    ['pdf.year', fn () => [Settlement::first()->meter_id, Settlement::first()->tenant_id, Settlement::first()->period->year]],
    ['pdf.qr-list', fn () => []],
    ['pdf.qr-labels', fn () => []],
    ['pdf.main-meters', fn () => [Settlement::first()->period->format('Y-m')]],
    ['pdf.consumption', fn () => [Settlement::first()->period->year]],
    ['pdf.difference', fn () => [Settlement::first()->period->year]],
]);

it('exports settlements as CSV and DATEV', function () {
    $month = Settlement::first()->period->format('Y-m');

    $csv = $this->actingAs($this->admin)->get(route('export.settlements', $month));
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Rechnungsnr.')->toContain('E-0001');

    $datev = $this->actingAs($this->admin)->get(route('export.datev', $month));
    expect($datev->streamedContent())->toContain('Belegfeld 1');
});

it('keeps the caretaker away from finance and administration', function (string $url) {
    $this->actingAs($this->caretaker)->get($url)->assertForbidden();
})->with(['/settlements', '/prices', '/admin/users', '/admin/settings', fn () => route('pdf.invoice', Settlement::first())]);

it('lets the caretaker record readings', function () {
    $meter = Meter::where('is_main', false)->first();

    Volt::actingAs($this->caretaker)->test('readings.status')
        ->call('open', $meter->id)
        ->set('value', $meter->latestReading()->value + 50)
        ->call('save')
        ->assertHasNoErrors();

    expect($meter->readings()->latest('id')->first()->source)->toBe(ReadingSource::Caretaker);
});

it('lets the viewer read but not change data', function () {
    $viewer = User::factory()->create(['role' => Role::Viewer]);

    $this->actingAs($viewer)->get('/settlements')->assertOk();
    $this->actingAs($viewer)->get('/admin/users')->assertForbidden();

    Volt::actingAs($viewer)->test('tenants.index')->call('create')->assertForbidden();
});

it('accepts a tenant reading via QR code with photo', function () {
    Storage::fake('local');
    $meter = Meter::where('is_main', false)->first();
    $last = $meter->latestReading()->value;

    $this->get(route('public.reading', $meter->qr_token))->assertOk()->assertSee($meter->number)->assertDontSee($meter->tenant->name);

    Volt::test('public.reading', ['token' => $meter->qr_token])
        ->set('value', $last + 30)
        ->set('reader_name', 'Max Mieter')
        ->set('photo', fakePhoto())
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('saved', true);

    $reading = $meter->readings()->latest('id')->first();
    expect($reading->source)->toBe(ReadingSource::TenantQr)
        ->and($reading->reader_name)->toBe('Max Mieter')
        ->and($reading->photo_path)->not->toBeNull();
    Storage::disk('local')->assertExists($reading->photo_path);
});

it('requires a photo from tenants and flags a decreasing value', function () {
    Storage::fake('local');
    $meter = Meter::where('is_main', false)->first();

    Volt::test('public.reading', ['token' => $meter->qr_token])
        ->set('value', 1)->set('reader_name', 'X')->call('save')
        ->assertHasErrors(['photo']);

    Volt::test('public.reading', ['token' => $meter->qr_token])
        ->set('value', 1)->set('reader_name', 'X')->set('photo', fakePhoto())->call('save');

    expect($meter->readings()->latest('id')->first()->status)->toBe(ReadingStatus::Pending);
});

it('rejects unknown QR tokens', function () {
    $this->get('/r/unknown-token')->assertNotFound();
});

it('redirects old QR codes to the new reading page', function () {
    $meter = Meter::first();
    $meter->update(['legacy_hash' => md5('1')]);

    $this->get('/reading.php?reader=tenant&meter='.md5('1'))->assertRedirect(route('public.reading', $meter->qr_token));
    $this->get('/reading.php?reader=tenant&meter='.md5('999'))->assertNotFound();

    config(['energie.legacy_qr' => false]);
    $this->get('/reading.php?reader=tenant&meter='.md5('1'))->assertNotFound();
});

it('logs in with username and blocks inactive users', function () {
    Volt::test('auth.login')->set('email', 'admin')->set('password', 'password')->call('login')->assertHasNoErrors();
    auth()->logout();

    $this->caretaker->update(['is_active' => false]);
    Volt::test('auth.login')->set('email', 'hausmeister')->set('password', 'password')->call('login')->assertHasErrors(['email']);
});

it('shows the interface in Polish when the user chooses it', function () {
    $this->actingAs($this->admin)->post(route('locale', 'pl'))->assertRedirect();

    expect($this->admin->fresh()->locale)->toBe('pl');
    $this->actingAs($this->admin->fresh())->get('/tenants')->assertSee('Dodaj najemcę')->assertSee('Rozliczenia');
});

it('writes changes to the activity log', function () {
    $tenant = Tenant::first();
    $this->actingAs($this->admin);
    $tenant->update(['phone' => '0431 123']);

    $this->assertDatabaseHas('activity_log', ['subject_type' => Tenant::class, 'subject_id' => $tenant->id, 'action' => 'updated', 'user_id' => $this->admin->id]);
});

it('creates a collective invoice from the settlement page and renders its PDF', function () {
    // Zwei Monate eines Mieters ohne eigene Rechnung.
    $items = Settlement::query()->orderBy('period')->take(2)->get();
    $tenantId = $items->first()->tenant_id;
    $items = Settlement::where('tenant_id', $tenantId)->orderBy('period')->take(3)->get();
    Settlement::whereKey($items->pluck('id'))->update(['invoice_number' => null, 'invoice_date' => null, 'is_invoiced' => false]);

    Volt::actingAs($this->admin)->test('settlements.index')
        ->call('openCollect', $tenantId)
        ->assertCount('collectIds', 3)
        ->call('collect')
        ->assertHasNoErrors();

    $collective = Settlement::where('type', 'collective')->sole();
    expect($collective->items)->toHaveCount(3)->and($collective->tenant_id)->toBe($tenantId);

    $pdf = $this->actingAs($this->admin)->get(route('pdf.invoice', $collective));
    $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $month = $collective->period->format('Y-m');
    expect($this->actingAs($this->admin)->get(route('export.settlements', $month))->streamedContent())
        ->toContain($collective->formattedNumber());

    $this->actingAs($this->admin)->get(route('settlements.index', ['monat' => $month]))
        ->assertOk()->assertSee($collective->formattedNumber());
});

it('shows a settlement summary and sends it to a chosen e-mail address', function () {
    Mail::fake();
    $settlement = Settlement::whereNotNull('invoice_number')->first();

    Volt::actingAs($this->admin)->test('settlements.index', ['month' => $settlement->period->format('Y-m')])
        ->call('showDetail', $settlement->id)
        ->assertSet('emailTo', $settlement->tenant->email)
        ->assertSee($settlement->formattedNumber())
        ->set('emailTo', 'buchhaltung@example.com')
        ->set('saveEmail', true)
        ->call('sendDetail')
        ->assertHasNoErrors()
        ->assertSee('Versendet');

    Mail::assertSent(InvoiceMail::class, fn ($mail) => $mail->hasTo('buchhaltung@example.com'));

    expect($settlement->fresh())
        ->emailed_to->toBe('buchhaltung@example.com')
        ->emailed_at->not->toBeNull()
        ->and($settlement->tenant->fresh()->email)->toBe('buchhaltung@example.com');
});

it('does not offer sending for a month without invoice number', function () {
    $settlement = Settlement::first();
    $settlement->update(['invoice_number' => null, 'is_invoiced' => false]);

    Volt::actingAs($this->admin)->test('settlements.index')
        ->call('showDetail', $settlement->id)
        ->assertSee('Sammelrechnung')
        ->call('sendDetail')
        ->assertHasErrors();
});

it('saves the end of a tenancy from the tenant form', function () {
    $tenant = Tenant::whereHas('meters')->first();

    Volt::actingAs($this->admin)->test('tenants.index')
        ->call('edit', $tenant->id)
        ->set('active_until', now()->subMonthNoOverflow()->endOfMonth()->toDateString())
        ->call('save')
        ->assertHasNoErrors();

    expect($tenant->fresh()->active_until->toDateString())->toBe(now()->subMonthNoOverflow()->endOfMonth()->toDateString())
        ->and($tenant->fresh()->is_active)->toBeFalse()
        ->and($tenant->meters()->count())->toBe(0);

    Volt::actingAs($this->admin)->test('tenants.index')
        ->call('edit', $tenant->id)
        ->set('active_until', '2000-01-01')
        ->call('save')
        ->assertHasErrors('active_until');
});

it('stores SMTP settings with an encrypted password and uses them for sending', function () {
    Volt::actingAs($this->admin)->test('admin.settings')
        ->set('values.mail_host', 'mn04.webd.pl')
        ->set('values.mail_port', '465')
        ->set('values.mail_encryption', 'ssl')
        ->set('values.mail_username', 'noreply@example.com')
        ->set('mailPassword', 'geheim-smtp')
        ->set('values.mail_from_address', 'noreply@example.com')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('mailPassword', '');

    $stored = Setting::find('mail_password')->value;
    expect($stored)->not->toContain('geheim-smtp')
        ->and(MailSettings::password())->toBe('geheim-smtp')
        ->and(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('mn04.webd.pl')
        ->and(config('mail.mailers.smtp.scheme'))->toBe('smtps');

    // Speichern ohne neues Passwort lässt das alte bestehen.
    Volt::actingAs($this->admin)->test('admin.settings')->call('save');
    expect(MailSettings::password())->toBe('geheim-smtp');
});

it('fills subject and text from the template and sends the edited version', function () {
    Mail::fake();
    Setting::put('mail_invoice_subject', 'Rechnung {rechnungsnummer} für {mieter}');
    $settlement = Settlement::whereNotNull('invoice_number')->first();

    $component = Volt::actingAs($this->admin)->test('settlements.index', ['month' => $settlement->period->format('Y-m')])
        ->call('showDetail', $settlement->id)
        ->assertSet('emailSubject', 'Rechnung '.$settlement->formattedNumber().' für '.$settlement->tenant->name);

    expect($component->get('emailBody'))->toContain($settlement->tenant->name)->toContain($settlement->formattedNumber());

    $component->set('emailSubject', 'Ihre Rechnung')
        ->set('emailBody', "Hallo,\n\nbitte beachten Sie die Anlage.")
        ->call('sendDetail')
        ->assertHasNoErrors();

    Mail::assertSent(InvoiceMail::class, fn ($mail) => $mail->subjectText === 'Ihre Rechnung'
        && str_contains($mail->bodyText, 'bitte beachten Sie die Anlage.'));
});

it('deletes the latest invoice from the detail dialog', function () {
    $last = Settlement::orderByDesc('invoice_number')->first();

    Volt::actingAs($this->admin)->test('settlements.index', ['month' => $last->period->format('Y-m')])
        ->call('showDetail', $last->id)
        ->assertSee('Rechnung löschen')
        ->call('deleteDetail');

    expect(Settlement::find($last->id))->toBeNull();
});

it('exports all settlements of a month including those without invoice number', function () {
    $settlement = Settlement::first();
    $month = $settlement->period->format('Y-m');
    Settlement::whereDate('period', $settlement->period)->update(['invoice_number' => null, 'is_invoiced' => false]);

    $csv = $this->actingAs($this->admin)->get(route('export.settlements', $month))->streamedContent();

    expect($csv)->toContain($settlement->tenant->name)->toContain('ohne Rechnung')
        ->and(substr_count($csv, "\n"))->toBe(Settlement::whereDate('period', $settlement->period)->count() + 1);
});

it('marks old settlements as invoiced outside the portal up to the chosen month', function () {
    Settlement::query()->update(['invoice_number' => null, 'is_invoiced' => false]);
    $months = Settlement::query()->orderBy('period')->pluck('period')->unique()->values();
    $cutoff = $months[1];

    Volt::actingAs($this->admin)->test('settlements.index', ['month' => $cutoff->format('Y-m')])
        ->call('markExternal');

    expect(Settlement::query()->openForCollection()->whereDate('period', '<=', $cutoff)->count())->toBe(0)
        ->and(Settlement::query()->openForCollection()->whereDate('period', '>', $cutoff)->count())->toBeGreaterThan(0);

    $csv = $this->actingAs($this->admin)->get(route('export.settlements', $cutoff->format('Y-m')))->streamedContent();
    expect($csv)->toContain('extern abgerechnet');
});

it('renders the monthly overview PDF with all settlements', function () {
    $month = Settlement::first()->period->format('Y-m');
    $response = $this->actingAs($this->admin)->get(route('pdf.overview', $month));

    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    $this->actingAs($this->caretaker)->get(route('pdf.overview', $month))->assertForbidden();
});

it('shows the reading status without login only with the secret link', function () {
    $this->get('/status/anything')->assertNotFound();

    Volt::actingAs($this->admin)->test('admin.settings')->call('generateStatusLink');
    $token = Setting::get('status_token');
    auth()->logout();

    expect($token)->toHaveLength(40);
    $meter = Meter::active()->first();
    $this->get('/status/'.$token)->assertOk()->assertSee($meter->number)->assertSee($meter->tenantUrl(), false);
    $this->get('/status/wrong-'.$token)->assertNotFound();

    Volt::actingAs($this->admin)->test('admin.settings')->call('disableStatusLink');
    auth()->logout();
    $this->get('/status/'.$token)->assertNotFound();
});

it('marks a single month as invoiced outside the portal and back', function () {
    $settlement = Settlement::first();
    $settlement->update(['invoice_number' => null, 'is_invoiced' => false]);

    $page = Volt::actingAs($this->admin)->test('settlements.index', ['month' => $settlement->period->format('Y-m')])
        ->call('showDetail', $settlement->id)
        ->assertSee('Als extern abgerechnet markieren')
        ->call('toggleExternal');

    expect($settlement->fresh()->is_invoiced)->toBeTrue();
    $page->assertSee('extern abgerechnet')->call('toggleExternal');
    expect($settlement->fresh()->is_invoiced)->toBeFalse();
});

it('links the settings through named routes so the app works in a subdirectory', function () {
    // Absolute Pfade wie href="/settings/profile" führen unter https://ascomm.pl/em/ zu 404.
    $views = collect(File::allFiles(resource_path('views')))
        ->filter(fn ($f) => preg_match('/(href|action)="\/[a-z]/', $f->getContents()));

    expect($views->map->getRelativePathname()->values()->all())->toBe([]);

    $this->actingAs($this->admin)->get('/settings/profile')->assertOk()->assertDontSee('Delete account');
    $this->actingAs($this->admin)->get('/dashboard')->assertSee('$flux.appearance', false);
});

it('exports all tenants with contact data and invoicing settings', function () {
    $flat = Tenant::first();
    $flat->update(['issues_invoices' => false, 'is_active' => false]);

    $csv = $this->actingAs($this->admin)->get(route('export.tenants'))->streamedContent();

    expect($csv)->toContain('Rechnungen ausstellen')->toContain($flat->name)
        ->and(substr_count($csv, "\n"))->toBe(Tenant::count() + 1);
    $this->actingAs($this->caretaker)->get(route('export.tenants'))->assertForbidden();
});

it('shows a flat-rate tenant settlement as control without invoice actions', function () {
    $settlement = Settlement::first();
    $settlement->update(['invoice_number' => null, 'is_invoiced' => false]);
    $settlement->tenant->update(['issues_invoices' => false]);

    Volt::actingAs($this->admin)->test('settlements.index', ['month' => $settlement->period->format('Y-m')])
        ->call('showDetail', $settlement->id)
        ->assertSee('Pauschalmieter')
        ->assertDontSee('Als extern abgerechnet markieren');

    $this->actingAs($this->admin)->get(route('pdf.invoice', $settlement))->assertOk();
});

it('names invoice PDFs after the invoice number with the configured prefix', function () {
    $settlement = Settlement::whereNotNull('invoice_number')->orderBy('invoice_number')->first();

    $this->actingAs($this->admin)->get(route('pdf.invoice', $settlement))
        ->assertHeader('Content-Disposition', 'inline; filename="KuB_'.$settlement->formattedNumber().'.pdf"');

    $mail = new InvoiceMail($settlement);
    expect($mail->attachments()[0]->as)->toBe('KuB_'.$settlement->formattedNumber().'.pdf');

    Setting::put('pdf_file_prefix', '');
    expect($settlement->fresh()->pdfFilename())->toBe($settlement->formattedNumber().'.pdf');
});

it('shows the copyright footer on app, login and public pages', function () {
    $footer = 'ASCOMM Adrian Sajnaga';
    $this->actingAs($this->admin)->get('/dashboard')->assertSee($footer);
    auth()->logout();
    $this->get('/login')->assertSee($footer);
    $this->get(route('public.reading', Meter::first()->qr_token))->assertSee($footer);
});

it('leaves inactive main meters out of all reports', function () {
    $main = Meter::where('is_main', true)->first();
    $old = Meter::factory()->main()->create(['number' => 'HZ-INAKTIV', 'is_active' => false]);
    ElectricityPrice::create(['meter_id' => $old->id, 'month' => now()->startOfMonth()->toDateString(), 'net_price' => 0.3, 'consumption_kwh' => 500]);

    $this->actingAs($this->admin)->get(route('reports.index', ['monat' => now()->format('Y-m')]))
        ->assertSee($main->number)->assertDontSee('HZ-INAKTIV');
});

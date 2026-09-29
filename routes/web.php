<?php

use App\Http\Controllers\ExportController;
use App\Http\Controllers\LegacyQrController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\ReadingPhotoController;
use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

Route::redirect('/', 'dashboard')->name('home');

// Öffentliche Zählerablesung über den QR-Code am Zähler.
Volt::route('r/{token}', 'public.reading')
    ->middleware('throttle:30,1')
    ->name('public.reading');

// Alte QR-Codes (reading.php?meter=<md5>) auf die neue Adresse umleiten.
Route::get('reading.php', LegacyQrController::class)->name('legacy.reading');

Route::post('locale/{locale}', LocaleController::class)->name('locale');

Route::middleware(['auth'])->group(function () {
    Volt::route('dashboard', 'dashboard')->name('dashboard');

    Volt::route('tenants', 'tenants.index')->name('tenants.index');
    Volt::route('meters', 'meters.index')->name('meters.index');
    Volt::route('meters/{meter}', 'meters.show')->name('meters.show');
    Volt::route('readings', 'readings.index')->name('readings.index');
    Volt::route('readings/status', 'readings.status')->name('readings.status');
    Route::get('readings/{reading}/photo', ReadingPhotoController::class)->name('readings.photo');

    Volt::route('settlements', 'settlements.index')->middleware('can:view-finance')->name('settlements.index');
    Volt::route('settlements/{meter}/{tenant}', 'settlements.create')->middleware('can:manage')->name('settlements.create');
    Volt::route('prices', 'prices.index')->middleware('can:view-finance')->name('prices.index');
    Volt::route('reports', 'reports.index')->name('reports.index');

    Route::prefix('pdf')->name('pdf.')->controller(PdfController::class)->group(function () {
        Route::get('invoice/{settlement}', 'invoice')->name('invoice');
        Route::get('invoices/{month}', 'invoices')->name('invoices');
        Route::get('year/{meter}/{tenant}/{year}', 'year')->name('year');
        Route::get('qr-list', 'qrList')->name('qr-list');
        Route::get('qr-labels', 'qrLabels')->name('qr-labels');
        Route::get('main-meters/{month}', 'mainMeters')->name('main-meters');
        Route::get('consumption/{year}', 'consumption')->name('consumption');
        Route::get('difference/{year}', 'difference')->name('difference');
    });

    Route::middleware('can:manage')->group(function () {
        Route::get('export/settlements/{month}', [ExportController::class, 'settlements'])->name('export.settlements');
        Route::get('export/datev/{month}', [ExportController::class, 'datev'])->name('export.datev');

        Volt::route('admin/users', 'admin.users')->name('admin.users');
        Volt::route('admin/settings', 'admin.settings')->name('admin.settings');
        Volt::route('admin/activity', 'admin.activity')->name('admin.activity');
    });

    Route::redirect('settings', 'settings/profile');

    Volt::route('settings/profile', 'settings.profile')->name('settings.profile');
    Volt::route('settings/password', 'settings.password')->name('settings.password');
    Volt::route('settings/appearance', 'settings.appearance')->name('settings.appearance');
});

require __DIR__.'/auth.php';

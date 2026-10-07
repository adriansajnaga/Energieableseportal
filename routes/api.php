<?php

use App\Http\Controllers\Api\AnalyzerIngestController;
use App\Http\Middleware\AuthenticateAnalyzer;
use Illuminate\Support\Facades\Route;

// Mobiler Analysator: Pakete mit Ablesungen (ohne Sitzung/CSRF, Bearer-Token je Gerät).
Route::post('analyzer/readings', AnalyzerIngestController::class)
    ->middleware([AuthenticateAnalyzer::class, 'throttle:analyzer'])
    ->name('api.analyzer.readings');

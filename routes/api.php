<?php

use App\Http\Controllers\Api\LiveBatchController;
use App\Http\Controllers\Api\LiveConnectorController;
use App\Http\Controllers\Api\LiveCoverageController;
use App\Http\Controllers\Api\LiveEventController;
use App\Http\Controllers\Api\LiveSessionController;
use App\Http\Middleware\EnsureApiTokenSessionIsValid;
use App\Http\Middleware\EnsureLiveAccess;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/live')->name('live.v1.')->middleware(['auth:sanctum', EnsureApiTokenSessionIsValid::class, EnsureLiveAccess::class, 'throttle:live'])->group(function (): void {
    Route::post('ingest/batches', [LiveBatchController::class, 'store'])->name('events.ingest');
    Route::get('sessions', [LiveSessionController::class, 'index'])->name('sessions.index');
    Route::post('sessions', [LiveSessionController::class, 'store'])->name('sessions.store');
    Route::get('sessions/{session}', [LiveSessionController::class, 'show'])->whereUuid('session')->name('sessions.show');
    Route::post('sessions/{session}/connectors', [LiveConnectorController::class, 'store'])->whereUuid('session')->name('connectors.store');
    Route::delete('sessions/{session}/connectors/{connector}', [LiveConnectorController::class, 'destroy'])->whereUuid(['session', 'connector'])->name('connectors.destroy');
    Route::get('sessions/{session}/coverage', [LiveCoverageController::class, 'index'])->whereUuid('session')->name('coverage.index');
    Route::get('sessions/{session}/events', [LiveEventController::class, 'index'])->whereUuid('session')->name('events.index');
});

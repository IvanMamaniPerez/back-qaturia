<?php

use App\Http\Controllers\Api\ApiContractController;
use App\Http\Middleware\EnsureApiDocumentationAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

Route::get('/', fn (): RedirectResponse => to_route('laravel-crm.dashboard'))->name('home');

Route::middleware([EnsureApiDocumentationAccess::class, 'throttle:api-contracts'])->group(function (): void {
    Route::get('.well-known/api-contracts', ApiContractController::class)->name('api-contracts.discovery');
    Route::get(config('api-contracts.http.path'), ApiContractController::class)->name('api-contracts.query');
});

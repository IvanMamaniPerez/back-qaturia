<?php

use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\RegistrationController;
use App\Http\Middleware\EnsureApiTokenSessionIsValid;
use App\Http\Middleware\EnsureFrontendSession;
use Illuminate\Support\Facades\Route;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\AuthController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\DealController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\InvoiceController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\LeadController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\OrderController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\OrganizationController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\PersonController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\ProductController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\ProductOptionController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\ProductVariationController;
use VentureDrake\LaravelCrm\Http\Controllers\Api\V2\QuoteController;

/*
 * Laravel CRM API routes (v2).
 *
 * This file is loaded by LaravelCrmServiceProvider::registerRoutes() under
 * the `crm/api/v2` prefix with the `api`, `laravel-crm.api.json`, and
 * `throttle:laravel-crm-api` middleware applied. Authenticated routes layer
 * `auth:sanctum`, `crm-api`, and `laravel-crm.api.team` on top.
 */

Route::post('auth/token', [AuthController::class, 'issueToken'])
    ->withoutMiddleware(EnsureFrontendSession::class)
    ->middleware('throttle:6,1')
    ->name('laravel-crm.api.v2.auth.token.issue');

Route::post('auth/register', [RegistrationController::class, 'store'])
    ->withoutMiddleware(EnsureFrontendSession::class)
    ->middleware('throttle:5,1')->name('laravel-crm.api.v2.auth.register');

Route::post('auth/forgot-password', [PasswordResetController::class, 'request'])
    ->withoutMiddleware(EnsureFrontendSession::class)
    ->middleware('throttle:5,1')->name('laravel-crm.api.v2.auth.password.request');

Route::post('auth/reset-password', [PasswordResetController::class, 'reset'])
    ->withoutMiddleware(EnsureFrontendSession::class)
    ->middleware('throttle:6,1')->name('laravel-crm.api.v2.auth.password.reset');

Route::post('auth/refresh', [AuthController::class, 'refresh'])
    ->withoutMiddleware(EnsureFrontendSession::class)
    ->middleware('throttle:20,1')->name('laravel-crm.api.v2.auth.refresh');

Route::post('auth/token/logout', [AuthController::class, 'logoutTokenSession'])
    ->withoutMiddleware(EnsureFrontendSession::class)
    ->name('laravel-crm.api.v2.auth.token.logout');

Route::post('auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:6,1')
    ->name('laravel-crm.api.v2.auth.login');

Route::post('auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum')
    ->name('laravel-crm.api.v2.auth.logout');

Route::middleware(['auth:sanctum', EnsureApiTokenSessionIsValid::class, 'crm-api', 'laravel-crm.api.team'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me'])
        ->name('laravel-crm.api.v2.auth.me');

    Route::delete('auth/token', [AuthController::class, 'revokeToken'])
        ->name('laravel-crm.api.v2.auth.token.revoke');

    Route::apiResource('leads', LeadController::class)
        ->names('laravel-crm.api.v2.leads')
        ->scoped(['lead' => 'external_id']);

    Route::apiResource('products', ProductController::class)
        ->names('laravel-crm.api.v2.products')
        ->scoped(['product' => 'external_id']);

    Route::prefix('products/{product:external_id}')->group(function () {
        $controller = ProductOptionController::class;
        Route::get('options', [$controller, 'index'])->name('laravel-crm.api.v2.products.options');
        Route::post('options/preview', [$controller, 'preview'])->name('laravel-crm.api.v2.products.options.preview');
        Route::post('options/apply', [$controller, 'apply'])->name('laravel-crm.api.v2.products.options.apply');
        Route::patch('options/{option}', [$controller, 'update'])->name('laravel-crm.api.v2.products.options.update');
        Route::patch('options/{option}/values/{value}', [$controller, 'update'])->name('laravel-crm.api.v2.products.options.values.update');
        Route::post('variations/generate/preview', [$controller, 'preview'])->name('laravel-crm.api.v2.products.variations.generate.preview');
        Route::post('variations/generate', [$controller, 'apply'])->name('laravel-crm.api.v2.products.variations.generate');
        Route::patch('variations/bulk', [$controller, 'bulk'])->name('laravel-crm.api.v2.products.variations.bulk');
        Route::post('variations/resolve', [$controller, 'resolve'])->name('laravel-crm.api.v2.products.variations.resolve');
        Route::post('variations/{variation}/archive', [$controller, 'archive'])->name('laravel-crm.api.v2.products.variations.archive');
        Route::post('variations/{variation}/restore', [$controller, 'restore'])->name('laravel-crm.api.v2.products.variations.restore');
        Route::post('legacy-variations/preview', [$controller, 'legacyPreview'])->name('laravel-crm.api.v2.products.legacy.preview');
        Route::post('legacy-variations/map', [$controller, 'apply'])->name('laravel-crm.api.v2.products.legacy.map');
    });

    // The variation resolves through Product::productVariations(), so a
    // variation of another product, or a soft-deleted one, is a 404.
    Route::apiResource('products.variations', ProductVariationController::class)
        ->parameters(['variations' => 'productVariation'])
        ->names('laravel-crm.api.v2.products.variations')
        ->scoped(['product' => 'external_id', 'productVariation' => 'external_id']);

    Route::apiResource('organizations', OrganizationController::class)
        ->names('laravel-crm.api.v2.organizations')
        ->scoped(['organization' => 'external_id']);

    Route::apiResource('people', PersonController::class)
        ->parameters(['people' => 'person'])
        ->names('laravel-crm.api.v2.people')
        ->scoped(['person' => 'external_id']);

    Route::apiResource('deals', DealController::class)
        ->names('laravel-crm.api.v2.deals')
        ->scoped(['deal' => 'external_id']);

    Route::apiResource('quotes', QuoteController::class)
        ->names('laravel-crm.api.v2.quotes')
        ->scoped(['quote' => 'external_id']);

    Route::apiResource('orders', OrderController::class)
        ->names('laravel-crm.api.v2.orders')
        ->scoped(['order' => 'external_id']);

    Route::apiResource('invoices', InvoiceController::class)
        ->names('laravel-crm.api.v2.invoices')
        ->scoped(['invoice' => 'external_id']);
});

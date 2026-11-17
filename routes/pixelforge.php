<?php

// Add to routes/web.php:  require __DIR__.'/pixelforge.php';

use App\Http\Controllers\AdminReportController;
use App\Http\Controllers\CreditController;
use App\Http\Controllers\GenerationController;
use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/pixelforge', PixelforgeWebhookController::class); // CSRF-exempt: see README

Route::middleware('auth')->group(function () {
    Route::get('/generations', [GenerationController::class, 'index'])->name('generations.index');
    Route::post('/generations', [GenerationController::class, 'store'])->middleware('throttle:20,1')->name('generations.store');
    Route::post('/generations/estimate', [GenerationController::class, 'estimate'])->middleware('throttle:60,1');
    Route::post('/credits', [CreditController::class, 'store'])->name('credits.store');
    Route::get('/admin/report', [AdminReportController::class, 'index'])->name('admin.report');
});

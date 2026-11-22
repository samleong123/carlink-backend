<?php

use App\Http\Controllers\Api\V1\PaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('payment-gateway/callback/{payment}/{callbackToken}', [PaymentController::class, 'callback'])->whereUuid('payment')->middleware('throttle:30,1')->name('payment.callback');
    Route::middleware(['auth:sanctum', 'tenant'])->prefix('organizations/{organization}')->group(function () {
        Route::post('payments', [PaymentController::class, 'create'])->name('payments.create');
        Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    });
});

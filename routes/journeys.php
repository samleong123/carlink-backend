<?php

use App\Http\Controllers\Api\V1\JourneyController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('journeys', [JourneyController::class, 'index']);
    Route::get('journeys/{journey}', [JourneyController::class, 'show'])->whereUuid('journey');
});

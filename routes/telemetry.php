<?php

use App\Http\Controllers\Api\V1\TelemetryController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum','tenant'])->group(function () {
    Route::get('vehicles/{vehicle}/telemetry',[TelemetryController::class,'samples']);
    Route::get('vehicles/{vehicle}/positions',[TelemetryController::class,'positions']);
});

<?php

use App\Http\Controllers\Api\V1\DriverShiftController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('drivers/{driver}/credentials', [DriverShiftController::class, 'credentials']);
    Route::post('drivers/{driver}/credentials', [DriverShiftController::class, 'enrol']);
    Route::post('drivers/{driver}/credentials/{credential}/revoke', [DriverShiftController::class, 'revoke']);
    Route::get('driver-shifts', [DriverShiftController::class, 'index']);
    Route::get('driver-shifts/{shift}', [DriverShiftController::class, 'show']);
    Route::post('driver-shifts/{shift}/close', [DriverShiftController::class, 'close']);
});

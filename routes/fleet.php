<?php

use App\Http\Controllers\Api\V1\FleetController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('{resource}', [FleetController::class, 'index'])->whereIn('resource', ['vehicles', 'drivers', 'devices']);
    Route::post('{resource}', [FleetController::class, 'store'])->whereIn('resource', ['vehicles', 'drivers', 'devices']);
    Route::get('{resource}/{id}', [FleetController::class, 'show'])->whereIn('resource', ['vehicles', 'drivers', 'devices']);
    Route::patch('{resource}/{id}', [FleetController::class, 'update'])->whereIn('resource', ['vehicles', 'drivers', 'devices']);
    Route::post('vehicles/{vehicle}/archive', [FleetController::class, 'archiveVehicle']);
    Route::get('devices/{device}/installations', [FleetController::class, 'installations']);
    Route::post('devices/{device}/installations', [FleetController::class, 'install']);
    Route::post('devices/{device}/installations/{installation}/close', [FleetController::class, 'closeInstallation']);
});

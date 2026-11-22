<?php

use App\Http\Controllers\Api\V1\MileageController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}/vehicles/{vehicle}')->middleware(['auth:sanctum','tenant'])->group(function () {
    Route::get('mileage', [MileageController::class, 'summary']);
    Route::get('mileage/segments', [MileageController::class, 'segments']);
    Route::post('odometer-observations', [MileageController::class, 'baseline']);
});

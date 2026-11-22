<?php

use App\Http\Controllers\Api\V1\MaintenanceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum','tenant'])->group(function () {
    Route::get('maintenance-plans', [MaintenanceController::class,'plans']);
    Route::post('maintenance-plans', [MaintenanceController::class,'createPlan']);
    Route::get('maintenance-plans/{plan}', [MaintenanceController::class,'showPlan']);
    Route::post('maintenance-plans/{plan}/revisions', [MaintenanceController::class,'revisePlan']);
    Route::post('vehicles/{vehicle}/maintenance-plan-assignments', [MaintenanceController::class,'assign']);
    Route::get('vehicles/{vehicle}/maintenance', [MaintenanceController::class,'vehiclePlans']);
    Route::get('vehicles/{vehicle}/service-records', [MaintenanceController::class,'records']);
    Route::get('maintenance/due-summary', [MaintenanceController::class,'dueSummary']);
    Route::get('maintenance/work-orders', [MaintenanceController::class,'orders']);
    Route::post('maintenance/work-orders', [MaintenanceController::class,'createOrder']);
    Route::get('maintenance/work-orders/{order}', [MaintenanceController::class,'showOrder']);
    Route::post('maintenance/work-orders/{order}/start', [MaintenanceController::class,'startOrder']);
    Route::post('maintenance/work-orders/{order}/cancel', [MaintenanceController::class,'cancelOrder']);
    Route::post('maintenance/work-orders/{order}/complete', [MaintenanceController::class,'completeOrder']);
});

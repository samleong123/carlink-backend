<?php

use App\Http\Controllers\Api\V1\OperationsAnalyticsController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum','tenant'])->group(function () {
    Route::get('invitations',[\App\Http\Controllers\Api\V1\InvitationController::class,'index']);
    Route::post('invitations',[\App\Http\Controllers\Api\V1\InvitationController::class,'create']);
    Route::post('invitations/{invitation}/revoke',[\App\Http\Controllers\Api\V1\InvitationController::class,'revoke']);
    Route::get('vehicles/{vehicle}/latest-state',[OperationsAnalyticsController::class,'latest']);
    Route::get('vehicles/{vehicle}/diagnostics',[OperationsAnalyticsController::class,'diagnostics']);
    Route::get('vehicles/{vehicle}/energy',[OperationsAnalyticsController::class,'energy']);
    Route::get('vehicles/{vehicle}/coverage',[OperationsAnalyticsController::class,'coverage']);
    Route::get('fleet-states',[OperationsAnalyticsController::class,'fleetStates']);
    Route::get('dashboard',[OperationsAnalyticsController::class,'dashboard']);
});
Route::post('v1/invitations/accept',[\App\Http\Controllers\Api\V1\InvitationController::class,'accept'])->middleware(['auth:sanctum','throttle:10,1']);

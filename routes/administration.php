<?php

use App\Http\Controllers\Api\V1\TenantAdministrationController as Tenant;
use App\Http\Controllers\Api\V1\LogController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum','tenant'])->group(function () {
    Route::patch('',[Tenant::class,'updateOrganization']);
    Route::get('settings',[Tenant::class,'settings']); Route::patch('settings',[Tenant::class,'updateSettings']);
    Route::get('members',[Tenant::class,'members']); Route::patch('members/{membership}',[Tenant::class,'updateMember']);
    Route::post('ownership-transfers',[Tenant::class,'transferOwnership']);
    Route::get('request-logs',[LogController::class,'requests']); Route::get('audit-logs',[LogController::class,'actions']);
    Route::post('audit-ai',[LogController::class,'explainActions'])->middleware('throttle:10,1');
});

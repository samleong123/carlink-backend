<?php

use App\Http\Controllers\Api\V1\ComplianceController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('compliance-types', [ComplianceController::class, 'types']);
    Route::post('compliance-types', [ComplianceController::class, 'createType']);
    Route::patch('compliance-types/{type}', [ComplianceController::class, 'updateType']);
    Route::get('compliance-obligations', [ComplianceController::class, 'obligations']);
    Route::post('compliance-obligations', [ComplianceController::class, 'createObligation']);
    Route::get('compliance-obligations/{obligation}', [ComplianceController::class, 'showObligation']);
    Route::get('compliance/status', [ComplianceController::class, 'status']);
    Route::get('compliance-records', [ComplianceController::class, 'records']);
    Route::post('compliance-obligations/{obligation}/renewals', [ComplianceController::class, 'renew']);
    Route::post('compliance-records/{record}/verify', [ComplianceController::class, 'verify']);
    Route::post('compliance-records/{record}/suspend', [ComplianceController::class, 'suspend']);
    Route::get('compliance-records/{record}/history', [ComplianceController::class, 'history']);
});

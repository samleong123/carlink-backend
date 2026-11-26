<?php

use App\Http\Controllers\Api\V1\IncidentController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('incidents/export.csv', [IncidentController::class, 'exportCsv']);
    Route::get('incidents', [IncidentController::class, 'index']);
    Route::post('incidents', [IncidentController::class, 'store']);
    Route::get('incidents/{incident}', [IncidentController::class, 'show'])->whereUuid('incident');
    Route::patch('incidents/{incident}', [IncidentController::class, 'update'])->whereUuid('incident');
    Route::post('incidents/{incident}/updates', [IncidentController::class, 'storeUpdate'])->whereUuid('incident');

    Route::get('me/incidents', [IncidentController::class, 'myIndex']);
    Route::post('me/incidents', [IncidentController::class, 'myStore']);
    Route::get('me/incidents/{incident}', [IncidentController::class, 'myShow'])->whereUuid('incident');
});

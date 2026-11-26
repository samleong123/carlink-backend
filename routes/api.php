<?php
use App\Http\Controllers\AuthController;
use App\Http\Controllers\OrganizationController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::get('health/live', fn (Request $request) => ApiResponse::success($request, ['service'=>'carlink'], 'Service Available'));
    Route::post('auth/magic-links', [AuthController::class, 'requestLink'])->middleware('throttle:6,1');
    Route::post('auth/signup-links', [AuthController::class, 'requestSignupLink'])->middleware('throttle:6,1');
    Route::get('public/plans', [\App\Http\Controllers\Api\V1\SignupController::class, 'plans'])->middleware('throttle:60,1');
    Route::post('auth/magic-links/consume', [AuthController::class, 'consume'])->middleware('throttle:10,1');
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('organization-signups', [\App\Http\Controllers\Api\V1\SignupController::class, 'latest']);
        Route::post('organization-signups', [\App\Http\Controllers\Api\V1\SignupController::class, 'create'])->middleware('throttle:6,1');
        Route::get('organization-signups/{signup}', [\App\Http\Controllers\Api\V1\SignupController::class, 'show'])->whereUuid('signup');
        Route::patch('me', [\App\Http\Controllers\AccountController::class, 'update']);
        Route::get('organizations', [OrganizationController::class, 'index']);
        Route::get('organizations/{organization}', [OrganizationController::class, 'show'])->middleware('tenant');
    });
});
if (file_exists(__DIR__.'/fleet.php')) { require __DIR__.'/fleet.php'; }
require __DIR__.'/operations-analytics.php';
require __DIR__.'/files.php';
if (file_exists(__DIR__.'/payment.php')) { require __DIR__.'/payment.php'; }
if (file_exists(__DIR__.'/driver_shifts.php')) { require __DIR__.'/driver_shifts.php'; }
if (file_exists(__DIR__.'/maintenance.php')) { require __DIR__.'/maintenance.php'; }
if (file_exists(__DIR__.'/notifications.php')) { require __DIR__.'/notifications.php'; }
if (file_exists(__DIR__.'/compliance.php')) { require __DIR__.'/compliance.php'; }
if (file_exists(__DIR__.'/mileage.php')) { require __DIR__.'/mileage.php'; }
if (file_exists(__DIR__.'/journeys.php')) { require __DIR__.'/journeys.php'; }
if (file_exists(__DIR__.'/incidents.php')) { require __DIR__.'/incidents.php'; }
require __DIR__.'/telemetry.php';
require __DIR__.'/administration.php';
require __DIR__.'/saas.php';

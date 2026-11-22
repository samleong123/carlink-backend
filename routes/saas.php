<?php
use App\Http\Controllers\Api\V1\{PlatformController,BillingController,DeviceManagementController,DeviceCommandController,DeviceDriverAuthController,DtcAiController,DriverSelfController,SafetyController,FleetInsightsController,AiInsightsController};
use Illuminate\Support\Facades\Route;

Route::prefix('v1/platform')->middleware(['auth:sanctum','platform:staff'])->group(function () {
    Route::get('devices',[DeviceManagementController::class,'index']);
    Route::get('devices/{device}',[DeviceManagementController::class,'show']);
    Route::post('devices/{device}/bluetooth',[DeviceManagementController::class,'configure']);
    Route::post('devices/{device}/commissioning',[DeviceManagementController::class,'commissioning'])->middleware('throttle:6,1');
    Route::post('devices/{device}/activate',[DeviceManagementController::class,'activate']);
    Route::middleware('platform:admin')->group(function () {
        Route::get('signup-archives',[\App\Http\Controllers\Api\V1\SignupController::class,'archives']);
        Route::get('signup-archives/{signup}',[\App\Http\Controllers\Api\V1\SignupController::class,'archive'])->whereUuid('signup');
        Route::get('plans',[PlatformController::class,'plans']); Route::post('plans',[PlatformController::class,'createPlan']); Route::patch('plans/{plan}',[PlatformController::class,'updatePlan']);
        Route::get('subscriptions',[PlatformController::class,'subscribers']); Route::patch('organizations/{organization}/subscription',[PlatformController::class,'subscription']);
        Route::post('organizations/{organization}/invoices',[BillingController::class,'issue']); Route::post('organizations/{organization}/invoices/{invoice}/void',[BillingController::class,'voidInvoice']);
        Route::get('organizations/{organization}/invoices',[BillingController::class,'invoices']); Route::get('organizations/{organization}/invoices/{invoice}',[BillingController::class,'invoice']);
        Route::post('devices/{device}/link',[DeviceManagementController::class,'link']); Route::post('devices/{device}/unlink',[DeviceManagementController::class,'unlink']);
        Route::get('organizations/{organization}/vehicles',[DeviceManagementController::class,'organizationVehicles']);
        Route::get('admins',[PlatformController::class,'admins']); Route::post('admins',[PlatformController::class,'addAdmin']); Route::patch('admins/{user}',[PlatformController::class,'updateAdmin']);
        Route::get('reports/summary',[PlatformController::class,'report']); Route::get('request-logs',[PlatformController::class,'logs']);
        Route::post('health-triage-ai',[AiInsightsController::class,'platformHealthTriage'])->middleware('throttle:10,1');
    });
});
Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum','tenant'])->group(function () {
    Route::get('plans',[BillingController::class,'plans']); Route::get('subscription',[BillingController::class,'show']);
    Route::get('invoices',[BillingController::class,'invoices']); Route::post('invoices',[BillingController::class,'issue']); Route::get('invoices/{invoice}',[BillingController::class,'invoice']); Route::post('invoices/{invoice}/pay',[BillingController::class,'pay']);
    Route::post('dtc-ai',[DtcAiController::class,'explain'])->middleware('throttle:10,1');
    Route::post('fleet-carbon-ai',[AiInsightsController::class,'fleetCarbon'])->middleware('throttle:10,1');
    Route::post('vehicles/{vehicle}/extended-dtc',[DeviceCommandController::class,'scan']); Route::get('device-commands/{command}',[DeviceCommandController::class,'status']);
    Route::post('vehicles/{vehicle}/configuration',[FleetInsightsController::class,'configuration']);
    Route::get('driver-assignments',[FleetInsightsController::class,'drivers']); Route::get('coverage-map',[FleetInsightsController::class,'coverage']); Route::get('reports/summary',[FleetInsightsController::class,'report']);
    Route::post('drivers/{driver}/photo',[FleetInsightsController::class,'uploadPhoto']); Route::get('drivers/{driver}/photo',[FleetInsightsController::class,'photo']);
    Route::get('drivers/{driver}/safety-score',[SafetyController::class,'score']); Route::get('safety-events',[SafetyController::class,'events']); Route::get('safety-appeals',[SafetyController::class,'appeals']); Route::post('safety-appeals/{appeal}/decision',[SafetyController::class,'decide']);
    foreach (['profile','records','score','events','appeals','energy','trips','vehicleInfo'] as $method) Route::get('driver/me/'.($method === 'vehicleInfo' ? 'vehicle-info' : $method),[DriverSelfController::class,$method]);
    Route::get('driver/me/trips/{segment}',[DriverSelfController::class,'tripDetail']);
    Route::post('driver/me/appeals',[DriverSelfController::class,'appeal']);
    Route::post('driver/me/eco-coach-ai',[AiInsightsController::class,'driverEcoCoach'])->middleware('throttle:10,1');
});
Route::prefix('v1/device/{device}')->middleware(['device-token','throttle:device-commands'])->group(function () {
    Route::get('settings',[DeviceCommandController::class,'settings']);
    Route::get('commands',[DeviceCommandController::class,'pending']); Route::post('commands/{command}/result',[DeviceCommandController::class,'result']);
    Route::post('dtc-ai',[DtcAiController::class,'device'])->middleware('throttle:device-dtc-ai');
    Route::post('driver-auth',[DeviceDriverAuthController::class,'authenticate']);
});

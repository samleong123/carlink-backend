<?php
use App\Http\Controllers\Api\V1\PrivateAssetController;
use Illuminate\Support\Facades\Route;
Route::prefix('v1/organizations/{organization}/files')->middleware(['auth:sanctum','tenant'])->group(function() {
    Route::get('',[PrivateAssetController::class,'index']);
    Route::post('',[PrivateAssetController::class,'store'])->middleware('throttle:12,1');
    Route::post('{asset}/link',[PrivateAssetController::class,'link'])->middleware('throttle:60,1');
    Route::post('{asset}/revoke',[PrivateAssetController::class,'revoke']);
});

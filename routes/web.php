<?php

use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/sanctum/csrf-cookie', fn (Request $request) => ApiResponse::success($request, [], 'CSRF Ready'));

// Compatibility retrieval endpoint now returns an authorized S3 presigned link in JSON, never bytes.
Route::get('/api/v1/organizations/{organization}/files/{asset}/content', [\App\Http\Controllers\Api\V1\PrivateAssetController::class,'content'])
    ->middleware(['auth:sanctum','tenant',\App\Http\Middleware\ExpireAbsoluteSession::class,\App\Http\Middleware\AuditAction::class,'throttle:60,1']);

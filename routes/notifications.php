<?php

use App\Http\Controllers\Api\V1\NotificationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/organizations/{organization}')->middleware(['auth:sanctum', 'tenant'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
    Route::post('notifications/mark-read', [NotificationController::class, 'markRead']);
    Route::patch('notifications/{notification}', [NotificationController::class, 'update'])->whereUuid('notification');
    Route::get('notification-preferences', [NotificationController::class, 'preferences']);
    Route::patch('notification-preferences', [NotificationController::class, 'updatePreferences']);
});

<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ScheduleSuggestionController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SurgeonController;
use App\Http\Controllers\Api\SurgeryController;
use Illuminate\Support\Facades\Route;

// -----------------------------------------------------------------------------
// Public auth
// -----------------------------------------------------------------------------
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// -----------------------------------------------------------------------------
// Authenticated (any role)
// -----------------------------------------------------------------------------
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // Notifications (shared)
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'markRead']);

    // -------------------------------------------------------------------------
    // Admin only
    // -------------------------------------------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::apiResource('rooms', RoomController::class);
        Route::apiResource('staff', StaffController::class);
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    });

    // -------------------------------------------------------------------------
    // Coordinator
    // -------------------------------------------------------------------------
    Route::middleware('role:coordinator')->group(function () {
        Route::apiResource('patients', PatientController::class);

        // Surgeries CRUD + calendar + auto-schedule (define specific routes BEFORE apiResource)
        Route::get('/surgeries/calendar', [SurgeryController::class, 'calendar']);
        Route::post('/surgeries/auto-schedule', [SurgeryController::class, 'autoSchedule']);
        Route::apiResource('surgeries', SurgeryController::class);

        Route::post('/schedule-suggestions/{suggestion}/accept', [ScheduleSuggestionController::class, 'accept']);
        Route::post('/schedule-suggestions/{suggestion}/reject', [ScheduleSuggestionController::class, 'reject']);
        Route::get('/schedule-suggestions', [ScheduleSuggestionController::class, 'index']);
    });

    // -------------------------------------------------------------------------
    // Surgeon
    // -------------------------------------------------------------------------
    Route::middleware('role:surgeon')->group(function () {
        Route::get('/my-surgeries', [SurgeonController::class, 'mySurgeries']);
        Route::post('/surgeries/{surgery}/start', [SurgeryController::class, 'start']);
        Route::post('/surgeries/{surgery}/complete', [SurgeryController::class, 'complete']);
        Route::post('/surgeries/{surgery}/delay', [SurgeryController::class, 'delay']);
    });
});

<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OperatingRoomSlotController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\ScheduleSuggestionController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SurgeonController;
use App\Http\Controllers\Api\SurgeryController;
use App\Http\Controllers\Api\SurgeryTypeController;
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

    // Reference data (shared) — needed by surgery-scheduling dropdowns.
    Route::get('/surgery-types', [SurgeryTypeController::class, 'index']);

    // -------------------------------------------------------------------------
    // Rooms & staff — reads shared with coordinators (scheduling dropdowns),
    // writes remain admin-only.
    // -------------------------------------------------------------------------
    Route::middleware('role:admin,coordinator')->group(function () {
        Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
        Route::get('/rooms/{room}', [RoomController::class, 'show'])->name('rooms.show');
        Route::get('/staff', [StaffController::class, 'index'])->name('staff.index');
        Route::get('/staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
    });

    // -------------------------------------------------------------------------
    // Admin only
    // -------------------------------------------------------------------------
    Route::middleware('role:admin')->group(function () {
        Route::get('/dashboard/stats', [DashboardController::class, 'stats']);
    });

    // -------------------------------------------------------------------------
    // Admin + coordinator — rooms/staff/patients/surgery-types writes are
    // shared between the two scheduling-capable roles.
    // -------------------------------------------------------------------------
    Route::middleware('role:admin,coordinator')->group(function () {
        Route::apiResource('rooms', RoomController::class)->except(['index', 'show']);
        Route::apiResource('staff', StaffController::class)->except(['index', 'show']);
        Route::apiResource('patients', PatientController::class);
        Route::apiResource('surgery-types', SurgeryTypeController::class)->except(['index']);

        Route::get('/rooms/{room}/slots', [OperatingRoomSlotController::class, 'index']);
        Route::post('/rooms/{room}/slots', [OperatingRoomSlotController::class, 'store']);
        Route::put('/rooms/{room}/slots/{slot}', [OperatingRoomSlotController::class, 'update']);
        Route::patch('/rooms/{room}/slots/{slot}', [OperatingRoomSlotController::class, 'update']);
        Route::delete('/rooms/{room}/slots/{slot}', [OperatingRoomSlotController::class, 'destroy']);

        Route::get('/rooms/{room}/surgeries', [RoomController::class, 'surgeries']);
    });

    // -------------------------------------------------------------------------
    // Coordinator
    // -------------------------------------------------------------------------
    Route::middleware('role:coordinator')->group(function () {
        Route::get('/surgeries', [SurgeryController::class, 'index']);

        // Surgeries CRUD + calendar + auto-schedule (define specific routes BEFORE apiResource)
        Route::get('/surgeries/calendar', [SurgeryController::class, 'calendar']);
        Route::post('/surgeries/auto-schedule', [SurgeryController::class, 'autoSchedule']);
        Route::post('/surgeries', [SurgeryController::class, 'store']);
        Route::put('/surgeries/{surgery}', [SurgeryController::class, 'update']);
        Route::patch('/surgeries/{surgery}', [SurgeryController::class, 'update']);
        Route::delete('/surgeries/{surgery}', [SurgeryController::class, 'destroy']);

        Route::post('/schedule-suggestions/{suggestion}/accept', [ScheduleSuggestionController::class, 'accept']);
        Route::post('/schedule-suggestions/{suggestion}/reject', [ScheduleSuggestionController::class, 'reject']);
        Route::get('/schedule-suggestions', [ScheduleSuggestionController::class, 'index']);
    });

    // -------------------------------------------------------------------------
    // Surgery detail — admins and coordinators can view any surgery;
    // surgeons can view only their own (enforced in the controller).
    // -------------------------------------------------------------------------
    Route::middleware('role:admin,coordinator,surgeon')->group(function () {
        Route::get('/surgeries/{surgery}', [SurgeryController::class, 'show']);
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

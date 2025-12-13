<?php

use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\RateLimitController;
use App\Http\Controllers\Admin\StatisticsController;
use App\Http\Controllers\ComplaintController;
use App\Http\Controllers\Employee\ComplaintController as EmployeeComplaintController;
use App\Http\Controllers\FcmTokenController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\EntityController;

// ============================================
// PUBLIC ROUTES (With Strict Rate Limiting)
// ============================================

Route::prefix('auth')->group(function () {
    // Registration - 10 per hour per IP, 3 per hour per email
    Route::post('/register', [RegisterController::class, 'register'])
        ->middleware('throttle:register');

    // Login - 5 per 5 minutes per email
    Route::post('/login', [LoginController::class, 'login'])
        ->middleware('throttle:login');

    // Token refresh - normal API rate
    Route::post('/refresh', [RefreshTokenController::class, 'refresh'])
        ->middleware('throttle:api');

    // ✅ Email verification - POST with email and code in body
    Route::post('/verify-email', [VerifyEmailController::class, 'verify'])
        ->middleware('throttle:email-verification');

    // ✅ Resend verification code - POST with email in body
    Route::post('/resend-email-code', [VerifyEmailController::class, 'resend'])
        ->middleware('throttle:email-verification');
});

// Protected auth routes
Route::middleware(['auth:api', 'throttle:api'])->prefix('auth')->group(function () {
    Route::post('/logout', [LogoutController::class, 'logout']);
    Route::post('/logout-all', [LogoutController::class, 'logoutAll']);
});

// ============================================
// FCM TOKEN MANAGEMENT
// ============================================
Route::middleware(['auth:api', 'throttle:fcm-register'])->prefix('fcm')->group(function () {
    Route::post('/register', [FcmTokenController::class, 'register']);
    Route::post('/remove', [FcmTokenController::class, 'remove']);
    Route::post('/test', [FcmTokenController::class, 'test']);
});

// ============================================
// CITIZEN COMPLAINT ROUTES
// ============================================
Route::middleware('auth:api')->prefix('complaints')->group(function () {
    // Create complaint - 10 per hour, 30 per day
    Route::post('/', [ComplaintController::class, 'store'])
        ->middleware('throttle:create-complaint');

    // List my complaints - normal API rate
    Route::get('/my', [ComplaintController::class, 'myComplaints'])
        ->middleware('throttle:api');

    // Track complaint - 10 per minute
    Route::get('/track/{trackingNumber}', [ComplaintController::class, 'track'])
        ->middleware('throttle:public-track');

    // Update complaint - 15 per minute
    Route::post('/{trackingNumber}/update', [ComplaintController::class, 'update'])
        ->middleware('throttle:update-complaint');

    // Delete attachment - includes file operations
    Route::delete('/{trackingNumber}/attachments/{attachmentId}', [ComplaintController::class, 'deleteAttachment'])
        ->middleware('throttle:file-upload');
});

// ============================================
// EMPLOYEE ROUTES
// ============================================
Route::prefix('employee')->middleware(['auth:api', 'employee', 'throttle:employee-actions'])->group(function () {

    // Employee Profile
    Route::get('/me', function() {
        $user = auth()->user();
        return response()->json([
            'data' => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
                'role' => $user->role,
                'is_active' => $user->is_active,
                'entity' => $user->entity ? [
                    'id' => $user->entity->id,
                    'name' => $user->entity->name,
                    'name_ar' => $user->entity->name_ar,
                    'type' => $user->entity->type,
                ] : null,
            ]
        ]);
    });

    // Complaint Management
    Route::prefix('complaints')->group(function () {
        // View operations - normal employee rate
        Route::get('/', [EmployeeComplaintController::class, 'index']);
        Route::get('/assigned', [EmployeeComplaintController::class, 'myAssignedComplaints']);
        Route::get('/{trackingNumber}', [EmployeeComplaintController::class, 'show']);

        // Action operations - employee actions rate limit
        Route::post('/{trackingNumber}/accept', [EmployeeComplaintController::class, 'accept']);
        Route::post('/{trackingNumber}/finish', [EmployeeComplaintController::class, 'finish']);
        Route::post('/{trackingNumber}/decline', [EmployeeComplaintController::class, 'decline']);
        Route::post('/{trackingNumber}/request-info', [EmployeeComplaintController::class, 'requestInfo']);
        Route::post('/{trackingNumber}/unlock', [EmployeeComplaintController::class, 'unlock']);
    });
});

// ============================================
// ADMIN ROUTES (Higher Limits)
// ============================================

// Public download endpoint (needs to be outside auth middleware)
Route::get('/admin/statistics/download/{filename}', [StatisticsController::class, 'download'])
    ->where('filename', '[a-zA-Z0-9_\-\.]+');

// Admin login (public)
Route::prefix('admin/auth')->group(function () {
    Route::post('/login', [AdminAuthController::class, 'login'])
        ->middleware('throttle:login');
});

// Protected admin routes
Route::prefix('admin')->middleware(['auth:api', 'admin', 'throttle:admin-api'])->group(function () {

    // Admin Auth
    Route::post('/auth/logout', [AdminAuthController::class, 'logout']);
    Route::get('/auth/me', [AdminAuthController::class, 'me']);
    Route::get('/rate-limits/statistics', [RateLimitController::class, 'statistics']);

    // Statistics & Analytics
    Route::prefix('statistics')->group(function () {
        Route::get('/overview', [StatisticsController::class, 'overview']);
        Route::get('/by-entity', [StatisticsController::class, 'byEntity']);
        Route::get('/trend', [StatisticsController::class, 'trend']);
        Route::get('/top-entities', [StatisticsController::class, 'topEntities']);

        // Exports
        Route::post('/export-csv', [StatisticsController::class, 'exportCsv']);
        Route::post('/export-pdf', [StatisticsController::class, 'exportPdf']);

        // Activity Log (Versioning)
        Route::get('/activity-log', [StatisticsController::class, 'activityLog']);

        // Performance Monitoring
        Route::get('/performance', [StatisticsController::class, 'performance']);
    });

    // Rate Limiting Control
    Route::prefix('rate-limiting')->group(function () {
        Route::get('/status', [StatisticsController::class, 'rateLimitingStatus']);
        Route::post('/enable', [StatisticsController::class, 'enableRateLimiting']);
        Route::post('/disable', [StatisticsController::class, 'disableRateLimiting']);
        Route::post('/toggle', [StatisticsController::class, 'toggleRateLimiting']);
    });

    // Entity Management
    Route::prefix('entities')->group(function () {
        Route::get('/', [EntityController::class, 'index']);
        Route::post('/', [EntityController::class, 'store']);
        Route::get('/{id}', [EntityController::class, 'show']);
        Route::put('/{id}', [EntityController::class, 'update']);
        Route::delete('/{id}', [EntityController::class, 'destroy']);
        Route::patch('/{id}/toggle-status', [EntityController::class, 'toggleStatus']);
        Route::get('/{entityId}/employees', [EmployeeController::class, 'getByEntity']);
    });

    // Employee Management
    Route::prefix('employees')->group(function () {
        Route::get('/', [EmployeeController::class, 'index']);
        Route::post('/', [EmployeeController::class, 'store']);
        Route::get('/{id}', [EmployeeController::class, 'show']);
        Route::put('/{id}', [EmployeeController::class, 'update']);
        Route::delete('/{id}', [EmployeeController::class, 'destroy']);
        Route::patch('/{id}/toggle-status', [EmployeeController::class, 'toggleStatus']);
    });
});

// ============================================
// TEST ENDPOINTS
// ============================================
Route::get('/server-info', function() {
    return response()->json([
        'server_port' => request()->server('SERVER_PORT'),
        'server_name' => gethostname(),
        'timestamp' => now()->toDateTimeString(),
        'random_id' => uniqid('req-'),
        'message' => 'Load balancing is working!',
    ]);
});

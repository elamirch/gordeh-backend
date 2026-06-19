<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\StoredFileController;
use App\Http\Controllers\LabTestController;
use App\Http\Controllers\InsuranceController;
use App\Http\Controllers\AdminDashboardController;

Route::post('auth/send-otp', [AuthController::class, 'sendotp']);
Route::post('auth/authenticate', [AuthController::class, 'authenticate']);
Route::post('auth/refresh', [AuthController::class, 'refreshTokens']);


Route::middleware(['auth:api', 'check_last_logout'])->group(function () {

    // --- Auth ---
    Route::get('auth/profile', [AuthController::class, 'profile']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::post('auth/logout-all', [AuthController::class, 'logoutAllDevices']);

    // --- Users ---
    Route::apiResource('users', UserController::class);

    // --- Stored Files ---
    Route::apiResource('stored-files', StoredFileController::class)->except(['update']);

    // --- Lab Tests ---
    Route::post('lab-tests', [LabTestController::class, 'store']);
    Route::get('lab-tests', [LabTestController::class, 'index']);
    Route::get('lab-tests/own', [LabTestController::class, 'indexOwn']);
    Route::get('lab-tests/{id}', [LabTestController::class, 'show']);

    Route::get('lab-tests/user/monthly-avg', [LabTestController::class, 'userMonthlyAvgGfr']);
    Route::get('lab-tests/monthly-avg', [LabTestController::class, 'allUsersMonthlyAvgGfr']);

    // --- Insurances ---
    Route::get('insurances', [InsuranceController::class, 'index']);
    Route::get('insurances/me', [InsuranceController::class, 'indexMe']);
    Route::post('insurances', [InsuranceController::class, 'store']);
    Route::get('insurances/{id}', [InsuranceController::class, 'show']);
    Route::patch('insurances/{id}', [InsuranceController::class, 'update']);
    Route::delete('insurances/{id}', [InsuranceController::class, 'destroy']);

    // --- Admin Dashboard ---
    Route::middleware('is_admin')->group(function () {
        Route::get('admin/dashboard', [AdminDashboardController::class, 'index']);
    });
});

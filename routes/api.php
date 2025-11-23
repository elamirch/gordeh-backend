<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StoredFileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\LabTestController;

Route::apiResource('users', UserController::class)
->middleware('auth:sanctum');

Route::apiResource('stored-files', StoredFileController::class)
->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('files/ocr', [StoredFileController::class, 'ocrSaveFile']);
    Route::post('files/profile', [StoredFileController::class, 'profileSaveFile']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('lab-tests', [LabTestController::class, 'store']);
    Route::get('lab-tests', [LabTestController::class, 'index']);
    Route::get('lab-tests/own', [LabTestController::class, 'indexOwn']);
    Route::get('lab-tests/{id}', [LabTestController::class, 'show']);
    Route::get('lab-tests/user/monthly-avg', [LabTestController::class, 'userMonthlyAvgGfr']);
    Route::get('lab-tests/monthly-avg', [LabTestController::class, 'allUsersMonthlyAvgGfr']);

});

use App\Http\Controllers\InsuranceController;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('insurances', [InsuranceController::class, 'index']);
    Route::get('insurances/me', [InsuranceController::class, 'indexMe']);
    Route::post('insurances', [InsuranceController::class, 'store']);
    Route::get('insurances/{id}', [InsuranceController::class, 'show']);
    Route::patch('insurances/{id}', [InsuranceController::class, 'update']);
    Route::delete('insurances/{id}', [InsuranceController::class, 'destroy']);
});

use App\Http\Controllers\AdminDashboardController;

Route::middleware(['auth:sanctum'])->group(function () {
    // Only admin users should be able to access — attach a middleware or gate accordingly
    Route::get('admin/dashboard', [AdminDashboardController::class, 'index']);
});

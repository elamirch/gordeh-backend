<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\StoredFileController;
use App\Http\Controllers\UserController;

Route::apiResource('users', UserController::class)
->middleware('auth:sanctum');

Route::apiResource('stored-files', StoredFileController::class)
->middleware('auth:sanctum');
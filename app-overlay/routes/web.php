<?php

use App\Http\Controllers\DemoController;
use App\Http\Middleware\RequestTelemetry;
use Illuminate\Support\Facades\Route;

Route::middleware(RequestTelemetry::class)->group(function () {
    Route::get('/', [DemoController::class, 'index']);
    Route::get('/api/sequential', [DemoController::class, 'sequential']);
    Route::get('/api/parallel', [DemoController::class, 'parallel']);
    Route::get('/api/error', [DemoController::class, 'error']);
});

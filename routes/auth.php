<?php

use App\Http\Controllers\Auth\AuthenticatedUserWebController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedUserWebController::class, 'create'])
                ->name('login');

    Route::post('login', [AuthenticatedUserWebController::class, 'store']);
});

Route::middleware('auth')->group(function () {

    // Route::post('/toggle-theme', [AuthenticatedUserWebController::class, 'toggle'])->name('toggle-theme');

    Route::post('logout', [AuthenticatedUserWebController::class, 'destroy'])
                ->name('logout');
});

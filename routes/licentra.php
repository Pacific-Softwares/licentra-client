<?php

use Illuminate\Support\Facades\Route;
use Ishalabs\Licentra\Laravel\Http\ActivationController;

Route::middleware([...config('licentra.route_middleware'), 'can:'.config('licentra.gate')])
    ->prefix(config('licentra.route_prefix'))
    ->name('licentra.')
    ->group(function () {
        Route::get('/', [ActivationController::class, 'show'])->name('activate');
        Route::post('/', [ActivationController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::post('/deactivate', [ActivationController::class, 'destroy'])->middleware('throttle:5,1')->name('deactivate');
    });

<?php

use Illuminate\Support\Facades\Route;
use Pacific\Licentra\Laravel\Http\ActivationController;
use Pacific\Licentra\Laravel\Http\ModulesController;
use Pacific\Licentra\Laravel\Http\UpdateController;

Route::middleware([...config('licentra.route_middleware'), 'can:'.config('licentra.gate')])
    ->prefix(config('licentra.route_prefix'))
    ->name('licentra.')
    ->group(function () {
        Route::get('/', [ActivationController::class, 'show'])->name('activate');
        Route::post('/', [ActivationController::class, 'store'])->middleware('throttle:10,1')->name('store');
        Route::post('/deactivate', [ActivationController::class, 'destroy'])->middleware('throttle:5,1')->name('deactivate');

        Route::get('/update', [UpdateController::class, 'show'])->name('update');
        Route::get('/update/status', [UpdateController::class, 'status'])->name('update.status');
        Route::post('/update/start', [UpdateController::class, 'start'])->middleware('throttle:10,1')->name('update.start');
        Route::post('/update/step', [UpdateController::class, 'step'])->name('update.step');
        Route::post('/update/reset', [UpdateController::class, 'reset'])->name('update.reset');

        if (\Pacific\Licentra\Laravel\LicentraServiceProvider::modules()['enabled']) {
            Route::get('/modules', [ModulesController::class, 'show'])->name('modules');
            Route::get('/modules/status', [ModulesController::class, 'status'])->name('modules.status');
            Route::post('/modules/start', [ModulesController::class, 'start'])->middleware('throttle:10,1')->name('modules.start');
            Route::post('/modules/step', [ModulesController::class, 'step'])->name('modules.step');
            Route::post('/modules/reset', [ModulesController::class, 'reset'])->name('modules.reset');
            Route::post('/modules/{slug}', [ModulesController::class, 'manage'])->middleware('throttle:10,1')
                ->where('slug', '[a-z][a-z0-9-]+')->name('modules.manage');
        }
    });

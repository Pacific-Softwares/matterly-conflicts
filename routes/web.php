<?php

use Illuminate\Support\Facades\Route;
use Modules\MatterlyConflicts\Http\ConflictsController;

// Everything here is under /matterly-conflicts and named "matterly-conflicts.…" (added by loadModuleRoutes()).

// Firm panel: staff who may see clients.
Route::middleware(['auth', 'firm.admin', 'firm.scope', 'firm.permission:clients'])->group(function () {
    Route::get('/', [ConflictsController::class, 'index'])->name('index');
    Route::post('/checks', [ConflictsController::class, 'store'])->middleware('throttle:60,1')->name('checks.store');
    Route::patch('/checks/{check}', [ConflictsController::class, 'resolve'])->whereNumber('check')->name('checks.resolve');
    Route::get('/checks.csv', [ConflictsController::class, 'export'])->name('checks.export');

    Route::get('/parties', [ConflictsController::class, 'parties'])->name('parties');
    Route::post('/parties', [ConflictsController::class, 'storeParty'])->name('parties.store');
    Route::delete('/parties/{party}', [ConflictsController::class, 'destroyParty'])->whereNumber('party')->name('parties.destroy');
});

// Super admin: usage across firms.
Route::middleware(['auth', 'super.admin'])->get('/admin', [ConflictsController::class, 'overview'])->name('admin');

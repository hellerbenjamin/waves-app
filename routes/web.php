<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TrackController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/tracks', [TrackController::class, 'index'])->name('tracks.index');
    Route::get('/tracks/{track}', [TrackController::class, 'show'])->name('tracks.show');
    Route::get('/tracks/{track}/stream', [TrackController::class, 'stream'])->name('tracks.stream');
    Route::patch('/tracks/{track}', [TrackController::class, 'update'])->name('tracks.update');
    Route::post('/tracks/upload-url', [TrackController::class, 'uploadUrl'])->name('tracks.upload-url');
    Route::put('/tracks/upload', [TrackController::class, 'uploadPut'])->middleware('signed')->name('tracks.upload-put');
    Route::post('/tracks', [TrackController::class, 'store'])->name('tracks.store');
    Route::delete('/tracks/{track}', [TrackController::class, 'destroy'])->name('tracks.destroy');
});

require __DIR__.'/auth.php';

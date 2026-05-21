<?php

use App\Http\Controllers\ChannelTemplateController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TrackController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public share links — no auth; the unguessable token is the access control.
Route::get('/share/{track:share_token}', [TrackController::class, 'showShared'])->name('tracks.shared');
Route::get('/share/{track:share_token}/stream', [TrackController::class, 'streamShared'])->name('tracks.shared-stream');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/tracks', [TrackController::class, 'index'])->name('tracks.index');
    Route::get('/tracks/{track}', [TrackController::class, 'show'])->name('tracks.show');
    Route::get('/tracks/{track}/stream', [TrackController::class, 'stream'])->name('tracks.stream');
    Route::patch('/tracks/{track}', [TrackController::class, 'update'])->name('tracks.update');
    Route::post('/tracks/upload-url', [TrackController::class, 'uploadUrl'])->name('tracks.upload-url');
    Route::put('/tracks/upload', [TrackController::class, 'uploadPut'])->middleware('signed')->name('tracks.upload-put');
    Route::post('/tracks', [TrackController::class, 'store'])->name('tracks.store');
    Route::delete('/tracks/{track}', [TrackController::class, 'destroy'])->name('tracks.destroy');
    Route::post('/tracks/{track}/share', [TrackController::class, 'share'])->name('tracks.share');
    Route::delete('/tracks/{track}/share', [TrackController::class, 'unshare'])->name('tracks.unshare');

    Route::post('/channel-templates', [ChannelTemplateController::class, 'store'])->name('channel-templates.store');
    Route::delete('/channel-templates/{channelTemplate}', [ChannelTemplateController::class, 'destroy'])->name('channel-templates.destroy');
});

require __DIR__.'/auth.php';

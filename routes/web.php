<?php

use App\Http\Controllers\ChannelTemplateController;
use App\Http\Controllers\CollectionController;
use App\Http\Controllers\ContributionController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventShareUploadController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicProfileController;
use App\Http\Controllers\TrackController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Public share links — no auth; the unguessable token is the access control.
Route::get('/share/{track:share_token}', [TrackController::class, 'showShared'])->name('tracks.shared');
Route::get('/share/{track:share_token}/channels/{channel}/stream', [TrackController::class, 'streamSharedChannel'])->whereNumber('channel')->name('tracks.shared-channels.stream');
Route::get('/share/{track:share_token}/channels/{channel}/peaks', [TrackController::class, 'peaksSharedChannel'])->whereNumber('channel')->name('tracks.shared-channels.peaks');

// A shared event reaches its own tracks and media through the event token, so
// individual items don't each need a share link.
Route::get('/events/share/{event:share_token}', [EventController::class, 'showShared'])->name('events.shared');
Route::get('/events/share/{event:share_token}/tracks/{track}', [EventController::class, 'showSharedTrack'])->name('events.shared.track-show');
Route::get('/events/share/{event:share_token}/tracks/{track}/channels/{channel}/stream', [EventController::class, 'streamSharedChannel'])->whereNumber('channel')->name('events.shared.channels.stream');
Route::get('/events/share/{event:share_token}/tracks/{track}/channels/{channel}/peaks', [EventController::class, 'peaksSharedChannel'])->whereNumber('channel')->name('events.shared.channels.peaks');
Route::get('/events/share/{event:share_token}/media/{media}/stream', [EventController::class, 'streamSharedMedia'])->name('events.shared.media-stream');
Route::get('/events/share/{event:share_token}/media/{media}/thumb', [EventController::class, 'thumbSharedMedia'])->name('events.shared.media-thumb');
Route::get('/events/share/{event:share_token}/media/{media}/download', [EventController::class, 'downloadSharedMedia'])->name('events.shared.media-download');
Route::get('/events/share/{event:share_token}/media/download-all', [EventController::class, 'downloadAllSharedMedia'])->name('events.shared.media.download-all');

// Anyone with the public event-share link can also upload photos/videos into
// the event. Token-bound (no auth); mirrors the contribute flow but without a
// separate invite — the share is the access control.
Route::post('/events/share/{event:share_token}/media/upload-url', [EventShareUploadController::class, 'uploadUrl'])->name('events.shared.media-upload-url');
Route::put('/events/share/{event:share_token}/media/upload', [EventShareUploadController::class, 'uploadPut'])->middleware('signed')->name('events.shared.media-upload-put');
Route::post('/events/share/{event:share_token}/media/multipart', [EventShareUploadController::class, 'createMultipart'])->name('events.shared.media-multipart-create');
Route::get('/events/share/{event:share_token}/media/multipart/sign', [EventShareUploadController::class, 'signPart'])->name('events.shared.media-multipart-sign');
Route::post('/events/share/{event:share_token}/media/multipart/complete', [EventShareUploadController::class, 'completeMultipart'])->name('events.shared.media-multipart-complete');
Route::post('/events/share/{event:share_token}/media/multipart/abort', [EventShareUploadController::class, 'abortMultipart'])->name('events.shared.media-multipart-abort');
Route::post('/events/share/{event:share_token}/media/cleanup', [EventShareUploadController::class, 'cleanup'])->name('events.shared.media-cleanup');
Route::post('/events/share/{event:share_token}/media', [EventShareUploadController::class, 'store'])->name('events.shared.media-store');

// A shared profile reaches all of a user's events (and their tracks/media)
// through the single profile token, so events don't each need their own link.
Route::get('/u/{user:share_token}', [PublicProfileController::class, 'show'])->name('profile.shared');
Route::get('/u/{user:share_token}/events/{event}', [PublicProfileController::class, 'showEvent'])->name('profile.shared.event');
Route::get('/u/{user:share_token}/events/{event}/tracks/{track}', [PublicProfileController::class, 'showTrack'])->name('profile.shared.track-show');
Route::get('/u/{user:share_token}/events/{event}/tracks/{track}/channels/{channel}/stream', [PublicProfileController::class, 'streamChannel'])->whereNumber('channel')->name('profile.shared.channels.stream');
Route::get('/u/{user:share_token}/events/{event}/tracks/{track}/channels/{channel}/peaks', [PublicProfileController::class, 'peaksChannel'])->whereNumber('channel')->name('profile.shared.channels.peaks');
Route::get('/u/{user:share_token}/events/{event}/media/{media}/stream', [PublicProfileController::class, 'streamMedia'])->name('profile.shared.media-stream');
Route::get('/u/{user:share_token}/events/{event}/media/{media}/thumb', [PublicProfileController::class, 'thumbMedia'])->name('profile.shared.media-thumb');
Route::get('/u/{user:share_token}/events/{event}/media/{media}/download', [PublicProfileController::class, 'downloadMedia'])->name('profile.shared.media-download');
Route::get('/u/{user:share_token}/events/{event}/media/download-all', [PublicProfileController::class, 'downloadAllMedia'])->name('profile.shared.media.download-all');

// A shared collection reaches its own photos and videos through the collection
// token, so individual items don't each need a share link.
Route::get('/collections/share/{collection:share_token}', [CollectionController::class, 'showShared'])->name('collections.shared');
Route::get('/collections/share/{collection:share_token}/media/{media}/stream', [CollectionController::class, 'streamSharedMedia'])->name('collections.shared.media-stream');
Route::get('/collections/share/{collection:share_token}/media/{media}/thumb', [CollectionController::class, 'thumbSharedMedia'])->name('collections.shared.media-thumb');
Route::get('/collections/share/{collection:share_token}/media/{media}/download', [CollectionController::class, 'downloadSharedMedia'])->name('collections.shared.media-download');
Route::get('/collections/share/{collection:share_token}/media/download-all', [CollectionController::class, 'downloadAllSharedMedia'])->name('collections.shared.media.download-all');

// Per-item media share links.
Route::get('/media/share/{media:share_token}', [MediaController::class, 'showShared'])->name('media.shared');
Route::get('/media/share/{media:share_token}/stream', [MediaController::class, 'streamShared'])->name('media.shared-stream');

// Anonymous contribution links — the inverse of sharing: the unguessable invite
// token lets someone upload photos/videos into an event with no account. The
// token (route-bound to an EventInvite) is the access control; no auth runs.
Route::get('/contribute/{invite}', [ContributionController::class, 'show'])->name('contribute.show');
Route::post('/contribute/{invite}/upload-url', [ContributionController::class, 'uploadUrl'])->name('contribute.upload-url');
Route::put('/contribute/{invite}/upload', [ContributionController::class, 'uploadPut'])->middleware('signed')->name('contribute.upload-put');
Route::post('/contribute/{invite}/multipart', [ContributionController::class, 'createMultipart'])->name('contribute.multipart.create');
Route::get('/contribute/{invite}/multipart/sign', [ContributionController::class, 'signPart'])->name('contribute.multipart.sign');
Route::post('/contribute/{invite}/multipart/complete', [ContributionController::class, 'completeMultipart'])->name('contribute.multipart.complete');
Route::post('/contribute/{invite}/multipart/abort', [ContributionController::class, 'abortMultipart'])->name('contribute.multipart.abort');
Route::post('/contribute/{invite}/cleanup', [ContributionController::class, 'cleanup'])->name('contribute.cleanup');
Route::post('/contribute/{invite}', [ContributionController::class, 'store'])->name('contribute.store');

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

// Throwaway sync test — used to evaluate whether N parallel <audio> elements
// stay locked tightly enough for the per-channel mixer to drive each channel
// as its own stream instead of a single multi-channel file. Delete once we
// have data.
Route::get('/sync-test', fn () => Inertia::render('SyncTest'))->middleware('auth')->name('sync-test');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
    Route::post('/profile/share', [ProfileController::class, 'share'])->name('profile.share');
    Route::delete('/profile/share', [ProfileController::class, 'unshare'])->name('profile.unshare');
});

Route::middleware('auth')->group(function () {
    Route::get('/tracks', [TrackController::class, 'index'])->name('tracks.index');
    Route::get('/tracks/{track}', [TrackController::class, 'show'])->name('tracks.show');
    Route::get('/tracks/{track}/channels/{channel}/stream', [TrackController::class, 'streamChannel'])->whereNumber('channel')->name('tracks.channels.stream');
    Route::get('/tracks/{track}/channels/{channel}/peaks', [TrackController::class, 'peaksChannel'])->whereNumber('channel')->name('tracks.channels.peaks');
    Route::patch('/tracks/{track}', [TrackController::class, 'update'])->name('tracks.update');

    // Browser-encoded multi-channel upload: init mints per-channel Opus + peaks
    // PUT targets, the browser uploads to them, then store() finalises the set
    // into Track + TrackChannel rows. uploadPut is the local-disk PUT target
    // (S3 presigns direct to the bucket instead).
    Route::post('/tracks/channels/init', [TrackController::class, 'initChannels'])->name('tracks.channels.init');
    Route::put('/tracks/upload', [TrackController::class, 'uploadPut'])->middleware('signed')->name('tracks.upload-put');
    Route::post('/tracks/cleanup', [TrackController::class, 'cleanup'])->name('tracks.cleanup');
    Route::post('/tracks', [TrackController::class, 'store'])->name('tracks.store');
    Route::delete('/tracks/{track}', [TrackController::class, 'destroy'])->name('tracks.destroy');
    Route::post('/tracks/{track}/share', [TrackController::class, 'share'])->name('tracks.share');
    Route::delete('/tracks/{track}/share', [TrackController::class, 'unshare'])->name('tracks.unshare');

    Route::post('/channel-templates', [ChannelTemplateController::class, 'store'])->name('channel-templates.store');
    Route::delete('/channel-templates/{channelTemplate}', [ChannelTemplateController::class, 'destroy'])->name('channel-templates.destroy');

    // Events — folders of tracks plus their photos and videos.
    Route::get('/events', [EventController::class, 'index'])->name('events.index');
    Route::post('/events', [EventController::class, 'store'])->name('events.store');
    Route::get('/events/{event}', [EventController::class, 'show'])->name('events.show');
    Route::patch('/events/{event}', [EventController::class, 'update'])->name('events.update');
    Route::delete('/events/{event}', [EventController::class, 'destroy'])->name('events.destroy');
    Route::post('/events/{event}/share', [EventController::class, 'share'])->name('events.share');
    Route::delete('/events/{event}/share', [EventController::class, 'unshare'])->name('events.unshare');
    Route::post('/events/{event}/tracks', [EventController::class, 'attachTracks'])->name('events.tracks.attach');
    Route::delete('/events/{event}/tracks/{track}', [EventController::class, 'detachTrack'])->name('events.tracks.detach');

    // Contribution links the owner mints and revokes. {eventInvite} (not the
    // public {invite}) so it binds by id, sidestepping the by-token binder.
    Route::post('/events/{event}/invites', [EventController::class, 'storeInvite'])->name('events.invites.store');
    Route::delete('/events/{event}/invites/{eventInvite}', [EventController::class, 'destroyInvite'])->name('events.invites.destroy');

    // Collections — shareable folders of photos and videos, curated across
    // events. Like events, they're folders: media survives the collection.
    Route::get('/collections', [CollectionController::class, 'index'])->name('collections.index');
    Route::post('/collections', [CollectionController::class, 'store'])->name('collections.store');
    Route::get('/collections/{collection}', [CollectionController::class, 'show'])->name('collections.show');
    Route::patch('/collections/{collection}', [CollectionController::class, 'update'])->name('collections.update');
    Route::delete('/collections/{collection}', [CollectionController::class, 'destroy'])->name('collections.destroy');
    Route::post('/collections/{collection}/share', [CollectionController::class, 'share'])->name('collections.share');
    Route::delete('/collections/{collection}/share', [CollectionController::class, 'unshare'])->name('collections.unshare');
    // Literal download-all is declared before the media/{media} wildcard so it wins the match.
    Route::get('/collections/{collection}/media/download-all', [CollectionController::class, 'downloadAllMedia'])->name('collections.media.download-all');
    Route::post('/collections/{collection}/media', [CollectionController::class, 'attachMedia'])->name('collections.media.attach');
    Route::delete('/collections/{collection}/media/{media}', [CollectionController::class, 'detachMedia'])->name('collections.media.detach');

    // Media — photos and videos. Literal upload/multipart routes are declared
    // before the /media/{media} wildcards so they win the match.
    Route::post('/media/upload-url', [MediaController::class, 'uploadUrl'])->name('media.upload-url');
    Route::put('/media/upload', [MediaController::class, 'uploadPut'])->middleware('signed')->name('media.upload-put');
    Route::post('/media/multipart', [MediaController::class, 'createMultipart'])->name('media.multipart.create');
    Route::get('/media/multipart/sign', [MediaController::class, 'signPart'])->name('media.multipart.sign');
    Route::post('/media/multipart/complete', [MediaController::class, 'completeMultipart'])->name('media.multipart.complete');
    Route::post('/media/multipart/abort', [MediaController::class, 'abortMultipart'])->name('media.multipart.abort');
    Route::post('/media/cleanup', [MediaController::class, 'cleanup'])->name('media.cleanup');
    Route::post('/media', [MediaController::class, 'store'])->name('media.store');
    Route::get('/media/{media}/stream', [MediaController::class, 'stream'])->name('media.stream');
    Route::get('/media/{media}/thumb', [MediaController::class, 'thumb'])->name('media.thumb');
    Route::get('/media/{media}/download', [MediaController::class, 'download'])->name('media.download');
    Route::get('/events/{event}/media/download-all', [EventController::class, 'downloadAllMedia'])->name('events.media.download-all');
    Route::delete('/media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');
    Route::post('/media/{media}/rotate', [MediaController::class, 'rotate'])->name('media.rotate');
    Route::post('/media/{media}/share', [MediaController::class, 'share'])->name('media.share');
    Route::delete('/media/{media}/share', [MediaController::class, 'unshare'])->name('media.unshare');
});

require __DIR__.'/auth.php';

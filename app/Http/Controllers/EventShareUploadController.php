<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SignsDirectUploads;
use App\Jobs\GenerateThumbnail;
use App\Jobs\TranscodeVideo;
use App\Models\Event;
use App\Models\Media;
use App\Services\MediaStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Anonymous media uploads scoped to an event's public share token. Mirrors
 * {@see ContributionController} (which uses an EventInvite token) — the
 * difference is just which token authorises the write. The event's own
 * share_token is the entire access control; no auth() runs.
 */
class EventShareUploadController extends Controller
{
    use SignsDirectUploads;

    public function __construct(private MediaStorage $storage) {}

    /** Single-PUT upload target for small files (multipart lives in the trait). */
    public function uploadUrl(Request $request, Event $event): array
    {
        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1', 'max:5368709120'], // 5 GB
            'content_type' => ['required', 'string', Rule::in(Media::ALLOWED_MIMES)],
        ]);

        return $this->storage->eventShareUploadTarget(
            $event,
            $validated['content_type'],
            Media::extensionForMime($validated['content_type']),
        );
    }

    public function store(Request $request, Event $event): RedirectResponse
    {
        $validated = $request->validate([
            's3_key' => ['required', 'string', 'starts_with:media/events/'.$event->id.'/'],
            'original_name' => ['required', 'string', 'max:255'],
            'mime' => ['required', 'string', Rule::in(Media::ALLOWED_MIMES)],
            'size' => ['required', 'integer', 'min:1'],
            'contributor_name' => ['nullable', 'string', 'max:120'],
        ]);

        abort_unless($this->storage->exists($validated['s3_key']), 422, 'Upload not found in storage.');

        // Row belongs to the event owner — same as the contribute flow — so
        // every existing policy/listing path treats it like an owner-attached
        // upload. The share viewer's optional name is recorded for attribution.
        $media = $event->media()->create([
            'user_id' => $event->user_id,
            'contributor_name' => $validated['contributor_name'] ?? null,
            's3_key' => $validated['s3_key'],
            'original_name' => $validated['original_name'],
            'mime' => $validated['mime'],
            'size' => $validated['size'],
            'kind' => Media::kindForMime($validated['mime']),
        ]);

        if ($media->kind === 'image') {
            GenerateThumbnail::dispatch($media);
        } elseif ($media->kind === 'video') {
            TranscodeVideo::dispatch($media);
        }

        return back();
    }

    protected function uploadStorage(): MediaStorage
    {
        return $this->storage;
    }

    protected function newUploadKey(Request $request, string $contentType): string
    {
        $event = $this->currentEvent($request);

        return $this->storage->newContribKey($event->id, Media::extensionForMime($contentType));
    }

    /**
     * The share token authorises the write; the key must additionally sit under
     * this event's contrib prefix so one link can't seed another event's
     * namespace.
     */
    protected function assertCanWriteKey(Request $request, string $key): void
    {
        $event = $this->currentEvent($request);

        abort_unless(str_starts_with($key, "media/events/{$event->id}/"), 403);
    }

    private function currentEvent(Request $request): Event
    {
        $event = $request->route('event');
        abort_unless($event instanceof Event, 404);

        return $event;
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\SignsDirectUploads;
use App\Http\Requests\StoreContributionRequest;
use App\Jobs\GenerateThumbnail;
use App\Jobs\TranscodeVideo;
use App\Models\EventInvite;
use App\Models\Media;
use App\Services\MediaStorage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Anonymous, token-scoped uploads into an event. There is no authenticated user
 * here: the unguessable invite token (route-bound to an {@see EventInvite}) is
 * the entire access control, mirroring the public share controllers for reads.
 * It reuses the owner upload machinery via {@see SignsDirectUploads}, only
 * swapping the user-prefix key/authorisation for an event-prefix one.
 */
class ContributionController extends Controller
{
    use SignsDirectUploads;

    public function __construct(private MediaStorage $storage) {}

    /** The contributor's upload page. Renders even for a dead link, to explain it. */
    public function show(EventInvite $invite): Response
    {
        $event = $invite->event;

        return Inertia::render('Contribute/Show', [
            'invite' => [
                'token' => $invite->token,
                'label' => $invite->label,
                'active' => $invite->isUsable(),
            ],
            'event' => [
                'name' => $event->name,
                'event_date' => $event->event_date?->toDateString(),
                'location' => $event->location,
            ],
        ]);
    }

    /** Single-PUT upload target for small files (the multipart path lives in the trait). */
    public function uploadUrl(Request $request, EventInvite $invite): array
    {
        abort_unless($invite->isUsable(), 410);

        $validated = $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            // Over 5 GB must use multipart; keep parity with the owner endpoint.
            'size' => ['required', 'integer', 'min:1', 'max:5368709120'],
            'content_type' => ['required', 'string', Rule::in(Media::ALLOWED_MIMES)],
        ]);

        return $this->storage->contribUploadTarget(
            $invite,
            $validated['content_type'],
            Media::extensionForMime($validated['content_type']),
        );
    }

    public function store(StoreContributionRequest $request, EventInvite $invite): RedirectResponse
    {
        abort_unless($invite->isUsable(), 410);

        $key = $request->validated('s3_key');
        abort_unless($this->storage->exists($key), 422, 'Upload not found in storage.');

        $mime = $request->validated('mime');

        // The row belongs to the event owner; the invite is recorded only as
        // attribution, so every existing policy/cascade/listing path is unchanged.
        $media = $invite->event->media()->create([
            'user_id' => $invite->event->user_id,
            'event_invite_id' => $invite->id,
            'contributor_name' => $request->validated('contributor_name'),
            's3_key' => $key,
            'original_name' => $request->validated('original_name'),
            'mime' => $mime,
            'size' => $request->validated('size'),
            'kind' => Media::kindForMime($mime),
        ]);

        $invite->increment('uploads_count');

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
        $invite = $this->currentInvite($request);

        return $this->storage->newContribKey($invite->event_id, Media::extensionForMime($contentType));
    }

    /**
     * The token authorises the write; the key must additionally fall under this
     * event's contrib prefix so one link can't seed another event's namespace.
     */
    protected function assertCanWriteKey(Request $request, string $key): void
    {
        $invite = $this->currentInvite($request);

        abort_unless(str_starts_with($key, "media/events/{$invite->event_id}/"), 403);
    }

    /** The invite bound to the current contribution route, guarded as usable. */
    private function currentInvite(Request $request): EventInvite
    {
        $invite = $request->route('invite');
        abort_unless($invite instanceof EventInvite, 404);
        abort_unless($invite->isUsable(), 410);

        return $invite;
    }
}

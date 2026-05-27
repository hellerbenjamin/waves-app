<?php

namespace App\Jobs;

use App\Models\Track;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Stub: split the source multi-channel WAV behind a Track into one mono Opus
 * (WebM) stream per channel via ffmpeg, persist a TrackChannel row for each,
 * extract a per-channel peaks envelope alongside, and delete the source WAV
 * once every channel is safely in R2. The job is queued after the upload
 * finalises; the player waits on TrackChannel rows to render.
 *
 * Implementation lands in the next commit — this file exists so the schema +
 * model + dispatch wiring can be in place first.
 */
class TranscodeTrackToChannels implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public function __construct(public Track $track) {}

    public function handle(): void
    {
        // TODO: ffmpeg pipeline. See notes on the class for the contract.
    }
}

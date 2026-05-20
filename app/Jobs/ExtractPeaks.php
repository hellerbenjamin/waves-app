<?php

namespace App\Jobs;

use App\Models\Track;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractPeaks implements ShouldQueue
{
    use Queueable;

    public function __construct(public Track $track)
    {
    }

    public function handle(): void
    {
        // Implemented in the next pass — runs ffprobe + ffmpeg per channel,
        // computes min/max peak pairs, persists to $this->track->peaks.
    }
}

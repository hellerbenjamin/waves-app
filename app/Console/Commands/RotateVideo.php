<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeVideo;
use App\Models\Media;
use Illuminate\Console\Command;

class RotateVideo extends Command
{
    protected $signature = 'media:rotate {media : Media id} {--degrees=90 : Clockwise rotation to apply (0, 90, 180, 270)}';

    protected $description = 'Set a video\'s rotation override and re-transcode it';

    public function handle(): void
    {
        $degrees = ((int) $this->option('degrees')) % 360;

        if (! in_array($degrees, [0, 90, 180, 270], true)) {
            $this->error('Degrees must be one of 0, 90, 180, 270.');

            return;
        }

        $media = Media::where('kind', 'video')->find($this->argument('media'));

        if (! $media) {
            $this->error('Video not found.');

            return;
        }

        $media->update(['rotation' => $degrees]);
        TranscodeVideo::dispatch($media);

        $this->info("Set rotation={$degrees}° on media #{$media->id} and queued a re-transcode.");
        $this->info('Run `artisan queue:work` if the worker is not already running.');
    }
}

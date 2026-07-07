<?php

namespace App\Console\Commands;

use App\Jobs\BackfillVideoDimensions as BackfillJob;
use App\Jobs\TranscodeVideo;
use App\Models\Media;
use Illuminate\Console\Command;

class BackfillVideoDimensions extends Command
{
    protected $signature = 'media:backfill-video-dimensions';

    protected $description = 'Correct stored video dimensions/orientation from each rendition (transcoding any that lack one)';

    public function handle(): void
    {
        $reprobe = 0;
        $transcode = 0;

        Media::where('kind', 'video')->each(function (Media $media) use (&$reprobe, &$transcode) {
            if ($media->playback_key) {
                BackfillJob::dispatch($media); // cheap: re-read the existing rendition
                $reprobe++;
            } else {
                TranscodeVideo::dispatch($media); // no rendition yet: full transcode
                $transcode++;
            }
        });

        if ($reprobe === 0 && $transcode === 0) {
            $this->info('No videos to process.');

            return;
        }

        $this->info("Queued {$reprobe} re-probe(s) and {$transcode} transcode(s).");
        $this->info('Run `artisan queue:work` if the worker is not already running.');
    }
}

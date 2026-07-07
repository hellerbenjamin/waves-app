<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeVideo;
use App\Models\Media;
use Illuminate\Console\Command;

class TranscodeVideos extends Command
{
    protected $signature = 'media:transcode-videos {--force : Re-transcode even if a rendition already exists}';

    protected $description = 'Queue web-rendition transcoding for uploaded videos';

    public function handle(): void
    {
        $query = Media::where('kind', 'video');

        if (! $this->option('force')) {
            $query->whereNull('playback_key');
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No videos to process.');

            return;
        }

        $this->info("Queueing transcodes for {$count} video(s)…");

        $query->each(fn (Media $media) => TranscodeVideo::dispatch($media));

        $this->info('Done. Run `artisan queue:work` if the worker is not already running.');
    }
}

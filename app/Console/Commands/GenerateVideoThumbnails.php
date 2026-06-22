<?php

namespace App\Console\Commands;

use App\Jobs\GenerateThumbnail;
use App\Models\Media;
use Illuminate\Console\Command;

class GenerateVideoThumbnails extends Command
{
    protected $signature = 'media:generate-video-thumbnails {--force : Re-generate even if a thumbnail already exists}';

    protected $description = 'Queue thumbnail generation for uploaded videos';

    public function handle(): void
    {
        $query = Media::where('kind', 'video');

        if (! $this->option('force')) {
            $query->whereNull('thumb_key');
        }

        $count = $query->count();

        if ($count === 0) {
            $this->info('No videos to process.');
            return;
        }

        $this->info("Queueing thumbnails for {$count} video(s)…");

        $query->each(fn (Media $media) => GenerateThumbnail::dispatch($media));

        $this->info('Done. Run `artisan queue:work` if the worker is not already running.');
    }
}

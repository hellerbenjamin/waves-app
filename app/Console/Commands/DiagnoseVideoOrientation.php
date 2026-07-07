<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\MediaStorage;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/** TEMPORARY diagnostic: report rotation metadata on video originals + renditions. */
class DiagnoseVideoOrientation extends Command
{
    protected $signature = 'media:diagnose-orientation {--limit=5}';

    protected $description = 'Report rotation metadata for video originals and renditions';

    public function handle(MediaStorage $storage): void
    {
        $videos = Media::where('kind', 'video')->latest()->limit((int) $this->option('limit'))->get();

        if ($videos->isEmpty()) {
            $this->warn('No video media found.');

            return;
        }

        foreach ($videos as $media) {
            $this->line('');
            $this->line("=== media #{$media->id}  stored {$media->width}x{$media->height}  playback_key=".($media->playback_key ?: 'NULL'));

            $this->probe('ORIGINAL ', $storage->ffmpegInput($media->s3_key));

            if ($media->playback_key) {
                $this->probe('RENDITION', $storage->ffmpegInput($media->playback_key));
            }
        }
    }

    private function probe(string $label, string $input): void
    {
        $p = new Process([
            'ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_entries', 'stream=width,height,coded_width,coded_height,side_data_list:stream_tags=rotate',
            '-of', 'json', $input,
        ]);
        $p->setTimeout(60);
        $p->run();

        $out = $p->getOutput() ?: $p->getErrorOutput();
        $this->line("  {$label}: ".trim(preg_replace('/\s+/', ' ', $out)));
    }
}

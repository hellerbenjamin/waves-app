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
        // Full stream dump so the Display Matrix side-data (where phones store
        // orientation) is always included, plus any legacy rotate tag.
        $p = new Process([
            'ffprobe', '-v', 'error', '-select_streams', 'v:0',
            '-show_streams', '-of', 'json', $input,
        ]);
        $p->setTimeout(60);
        $p->run();

        $out = $p->getOutput() ?: $p->getErrorOutput();

        // Pull out just the fields that matter for orientation.
        $data = json_decode($out, true);
        $stream = $data['streams'][0] ?? [];
        $rotation = null;
        $matrix = false;
        foreach ($stream['side_data_list'] ?? [] as $sd) {
            if (isset($sd['rotation'])) {
                $rotation = $sd['rotation'];
            }
            if (($sd['side_data_type'] ?? '') === 'Display Matrix') {
                $matrix = true;
            }
        }

        $summary = sprintf(
            'coded=%sx%s  displayW/H=%sx%s  DisplayMatrix=%s  rotation=%s  rotateTag=%s',
            $stream['coded_width'] ?? '?',
            $stream['coded_height'] ?? '?',
            $stream['width'] ?? '?',
            $stream['height'] ?? '?',
            $matrix ? 'yes' : 'no',
            $rotation ?? 'none',
            $stream['tags']['rotate'] ?? 'none',
        );

        $this->line("  {$label}: {$summary}");
    }
}

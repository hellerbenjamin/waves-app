<?php

namespace App\Console\Commands;

use App\Jobs\TranscodeTrackToChannels;
use App\Models\Track;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ReprocessTracks extends Command
{
    protected $signature = 'tracks:reprocess
        {track?* : One or more track IDs; omit to target all tracks}
        {--missing : Only tracks that have no per-channel rows yet}
        {--sync : Run inline instead of queueing (no queue:work needed)}';

    protected $description = 'Re-run the per-channel Opus transcode for tracks';

    public function handle(): int
    {
        $ids = $this->argument('track');

        $tracks = Track::query()
            ->when($ids, fn (Builder $q) => $q->whereIn('id', $ids))
            ->when($this->option('missing'), fn (Builder $q) => $q->whereDoesntHave('channels'))
            ->get();

        if ($tracks->isEmpty()) {
            $this->warn('No matching tracks.');

            return self::SUCCESS;
        }

        $sync = $this->option('sync');
        $this->info(sprintf('Reprocessing %d track(s) %s…', $tracks->count(), $sync ? 'synchronously' : 'via queue'));

        foreach ($tracks as $track) {
            $sync
                ? TranscodeTrackToChannels::dispatchSync($track)
                : TranscodeTrackToChannels::dispatch($track);

            $this->line("  #{$track->id}  {$track->original_name}");
        }

        $this->info($sync
            ? 'Done.'
            : 'Queued. Ensure a worker is running: ddev artisan queue:work');

        return self::SUCCESS;
    }
}

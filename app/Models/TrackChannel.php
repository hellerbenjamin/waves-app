<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One mono Opus stream of a Track. The set of TrackChannels belonging to a
 * track is the track's audio — there is no separate multi-channel master.
 * The player plays each channel through its own <audio> element + Web Audio
 * mixer node, which is why per-channel sync was tested before this landed.
 */
class TrackChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'track_id',
        'channel_index',
        's3_key',
        'peaks_s3_key',
        'label',
        'size',
    ];

    protected $casts = [
        'channel_index' => 'integer',
        'size' => 'integer',
    ];

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }
}

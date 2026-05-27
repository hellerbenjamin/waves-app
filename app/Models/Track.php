<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Track extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'event_id',
        's3_key',
        'original_name',
        'mime',
        'size',
        'content_hash',
        'channels_count',
        'sample_rate',
        'peaks_ready',
        'channel_labels',
        'default_mix',
        'duration_seconds',
        'share_token',
    ];

    protected $casts = [
        'channel_labels' => 'array',
        'default_mix' => 'array',
        'size' => 'integer',
        'channels_count' => 'integer',
        'sample_rate' => 'integer',
        'peaks_ready' => 'boolean',
        'duration_seconds' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * The track's audio, one row per source-channel mono Opus stream. Always
     * iterate in `channel_index` order — the mixer UI labels by position.
     */
    public function channels(): HasMany
    {
        return $this->hasMany(TrackChannel::class)->orderBy('channel_index');
    }

    /** Cheap columns the track cards render from — never carries the peaks envelope (it lives in object storage). */
    public function scopeForCards(Builder $query): Builder
    {
        return $query->select([
            'id', 'event_id', 'original_name', 'duration_seconds', 's3_key', 'peaks_ready', 'channels_count',
        ]);
    }
}

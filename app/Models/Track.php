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
        'parent_track_id',
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
        'split_proposal',
    ];

    protected $casts = [
        'channel_labels' => 'array',
        'default_mix' => 'array',
        'split_proposal' => 'array',
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

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Track::class, 'parent_track_id');
    }

    /** Children created by splitting this track. */
    public function children(): HasMany
    {
        return $this->hasMany(Track::class, 'parent_track_id');
    }

    /** Cheap columns the track cards render from — never carries the peaks envelope (it lives in object storage). */
    public function scopeForCards(Builder $query): Builder
    {
        return $query->select([
            'id', 'event_id', 'original_name', 'duration_seconds', 's3_key', 'peaks_ready', 'channels_count',
        ]);
    }
}

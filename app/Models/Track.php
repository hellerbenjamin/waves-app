<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Track extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        // Drop pivot rows so a deleted track leaves no orphan collection entries.
        static::deleting(fn (Track $track) => $track->collections()->detach());
    }

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

    /** Collections this track has been curated into (many-to-many, cross-event). */
    public function collections(): MorphToMany
    {
        return $this->morphToMany(Collection::class, 'collectable', 'collectables');
    }

    /**
     * The track's audio, one row per source-channel mono Opus stream. Always
     * iterate in `channel_index` order — the mixer UI labels by position.
     */
    public function channels(): HasMany
    {
        return $this->hasMany(TrackChannel::class)->orderBy('channel_index');
    }

    /** Cheap columns the track cards render from — `channels_count` doubles as the readiness signal once the transcode job has populated channel rows. Columns are table-qualified so the scope survives a pivot join (e.g. collections). */
    public function scopeForCards(Builder $query): Builder
    {
        return $query->select([
            'tracks.id', 'tracks.event_id', 'tracks.original_name', 'tracks.duration_seconds', 'tracks.s3_key', 'tracks.channels_count',
        ]);
    }
}

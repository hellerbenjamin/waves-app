<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
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
        'peaks',
        'channel_labels',
        'duration_seconds',
        'share_token',
        'split_proposal',
    ];

    protected $casts = [
        'peaks' => 'array',
        'channel_labels' => 'array',
        'split_proposal' => 'array',
        'size' => 'integer',
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

    protected function peaksReady(): Attribute
    {
        return Attribute::get(function () {
            // List/sort queries (see scopeForCards) select a cheap
            // `peaks is not null` flag instead of the peaks JSON itself: that
            // payload can be huge for multi-GB tracks, and carrying it through a
            // filesort blows MySQL's sort buffer ("Out of sort memory"). Prefer
            // that flag when present; otherwise derive it from the loaded peaks.
            if (array_key_exists('peaks_ready', $this->attributes)) {
                return (bool) $this->attributes['peaks_ready'];
            }

            return $this->peaks !== null;
        });
    }

    /**
     * Columns the track cards need, with `peaks_ready` standing in for the heavy
     * `peaks` payload so listing/sorting never loads it.
     */
    public function scopeForCards(Builder $query): Builder
    {
        return $query
            ->select(['id', 'event_id', 'original_name', 'duration_seconds', 's3_key'])
            ->selectRaw('peaks is not null as peaks_ready');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * A cross-event collection: a curated, shareable set of tracks and media pulled
 * from any of the owner's events. Membership is polymorphic (the `collectables`
 * pivot), kept distinct from the `event_id` folder relationship on purpose.
 */
class Collection extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'share_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tracks(): MorphToMany
    {
        return $this->morphedByMany(Track::class, 'collectable', 'collectables')->withTimestamps();
    }

    public function media(): MorphToMany
    {
        return $this->morphedByMany(Media::class, 'collectable', 'collectables')->withTimestamps();
    }
}

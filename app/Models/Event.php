<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    /** The selectable event kinds, shared with validation and the UI. */
    public const TYPES = ['live_show', 'rehearsal', 'studio_session', 'other'];

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'event_date',
        'location',
        'description',
        'share_token',
    ];

    protected $casts = [
        'event_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /** Contribution links that let people upload into this event without an account. */
    public function invites(): HasMany
    {
        return $this->hasMany(EventInvite::class);
    }
}

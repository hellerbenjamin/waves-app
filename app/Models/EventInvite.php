<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An unguessable per-event write-token. It lets someone with the link upload
 * photos/videos into the event without an account — the mirror of how a
 * Track/Event/Media share_token grants anonymous read. The minted media still
 * belongs to the event owner; the invite only records who was allowed to add it.
 */
class EventInvite extends Model
{
    use HasFactory;

    protected $fillable = [
        'event_id',
        'created_by',
        'token',
        'label',
        'expires_at',
        'revoked_at',
        'uploads_count',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'revoked_at' => 'datetime',
        'uploads_count' => 'integer',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class);
    }

    /** A link accepts uploads only while it is neither revoked nor expired. */
    public function isUsable(): bool
    {
        return $this->revoked_at === null
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }
}

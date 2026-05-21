<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Track extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        's3_key',
        'original_name',
        'mime',
        'size',
        'content_hash',
        'peaks',
        'channel_labels',
        'duration_seconds',
        'share_token',
    ];

    protected $casts = [
        'peaks' => 'array',
        'channel_labels' => 'array',
        'size' => 'integer',
        'duration_seconds' => 'float',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function peaksReady(): Attribute
    {
        return Attribute::get(fn () => $this->peaks !== null);
    }
}

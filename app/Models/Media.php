<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Media extends Model
{
    use HasFactory;

    // Eloquent would otherwise pluralise to "medias".
    protected $table = 'media';

    /**
     * MIME types accepted for upload. Images and the web-playable video
     * containers; the allowlist is the single source of truth for both the
     * upload-URL and store requests.
     */
    public const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/x-matroska',
    ];

    protected $fillable = [
        'user_id',
        'event_id',
        'collection_id',
        'event_invite_id',
        'contributor_name',
        's3_key',
        'playback_key',
        'original_name',
        'mime',
        'size',
        'kind',
        'width',
        'height',
        'duration',
        'rotation',
        'thumb_key',
        'share_token',
    ];

    protected $casts = [
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'duration' => 'integer',
        'rotation' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /** The shareable collection this belongs to, if curated into one; independent of its event. */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(Collection::class);
    }

    /** The contribution link this was uploaded through, if anonymous; null for owner uploads. */
    public function invite(): BelongsTo
    {
        return $this->belongsTo(EventInvite::class, 'event_invite_id');
    }

    /** The storage key to stream for playback: the web rendition when ready, else the original. */
    public function playbackKey(): string
    {
        return $this->playback_key ?? $this->s3_key;
    }

    /** The MIME type served for playback — always mp4 once a rendition exists. */
    public function playbackMime(): ?string
    {
        return $this->playback_key ? 'video/mp4' : $this->mime;
    }

    /** Classify an upload as 'image' or 'video' from its MIME type. */
    public static function kindForMime(string $mime): ?string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'video/') => 'video',
            default => null,
        };
    }

    /** The canonical file extension for an allowed MIME type. */
    public static function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-matroska' => 'mkv',
            default => 'bin',
        };
    }
}

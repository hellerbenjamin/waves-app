<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Track now stores its audio as one mono Opus file per channel, not a single
 * multi-channel WAV. Each row here is one channel: its object key, optional
 * per-channel peaks envelope, and a label inherited from the source WAV's
 * `default_mix`. The player loads N rows and runs N parallel <audio> elements
 * through its Web Audio mixer; sub-millisecond drift across elements was
 * confirmed by the throwaway /sync-test page before this refactor landed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('track_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('track_id')->constrained()->cascadeOnDelete();
            // 0-based, mirrors the channel order in the source multi-channel WAV.
            $table->unsignedSmallInteger('channel_index');
            // Mono Opus-in-WebM object key, scoped under the track owner.
            $table->string('s3_key')->unique();
            // Per-channel envelope (JSON) lives alongside the Opus in R2; we just
            // remember the key so signed URLs can be issued on demand.
            $table->string('peaks_s3_key')->nullable();
            // Display name pulled from `tracks.default_mix[].label` at transcode
            // time. Null falls back to "Channel N" in the UI.
            $table->string('label')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->timestamps();

            // Each channel slot of a track is unique; the FK already covers
            // track-scoped lookups but the composite makes upserts safe.
            $table->unique(['track_id', 'channel_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('track_channels');
    }
};

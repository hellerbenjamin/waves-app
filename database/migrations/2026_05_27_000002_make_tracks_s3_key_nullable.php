<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tracks.s3_key` no longer points at the playable audio — the per-channel
 * Opus files (TrackChannel rows) do. The key now lives there only between
 * upload finalise and the transcode job; once channels are written the job
 * deletes the WAV and nulls the column. The DB constraint follows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->string('s3_key')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->string('s3_key')->nullable(false)->change();
        });
    }
};

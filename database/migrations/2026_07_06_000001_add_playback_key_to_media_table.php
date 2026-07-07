<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // The downscaled, faststart web rendition streamed for playback;
            // null until TranscodeVideo runs (serving falls back to s3_key).
            $table->string('playback_key')->nullable()->after('s3_key');
            // Video length in seconds, from ffprobe during transcode.
            $table->unsignedInteger('duration')->nullable()->after('height');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn(['playback_key', 'duration']);
        });
    }
};

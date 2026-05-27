<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hoist the small bits of metadata the app actually queries out of the peaks
 * JSON (channels count, sample rate, readiness flag), and drop the heavy
 * `peaks` payload — it now lives next to the WAV in object storage as a
 * sibling `.peaks.json` file. The user will regenerate envelopes via
 * `tracks:reprocess`, so no data backfill happens here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->unsignedSmallInteger('channels_count')->nullable()->after('duration_seconds');
            $table->unsignedInteger('sample_rate')->nullable()->after('channels_count');
            $table->boolean('peaks_ready')->default(false)->after('sample_rate');
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('peaks');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->json('peaks')->nullable()->after('content_hash');
        });

        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn(['channels_count', 'sample_rate', 'peaks_ready']);
        });
    }
};

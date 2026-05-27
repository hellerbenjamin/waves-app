<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `peaks_ready` was the legacy readiness signal for the per-track peaks JSON.
 * With the per-channel pipeline, readiness is "does this track have
 * TrackChannel rows?" — derived in the presenter, not stored on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('peaks_ready');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->boolean('peaks_ready')->default(false);
        });
    }
};

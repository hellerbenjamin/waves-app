<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Clockwise degrees applied to the rendition on top of ffmpeg's
            // autorotate. null means "not yet decided" — the transcode job runs
            // the assume-portrait heuristic and stores the value it applied.
            $table->unsignedSmallInteger('rotation')->nullable()->after('duration');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn('rotation');
        });
    }
};

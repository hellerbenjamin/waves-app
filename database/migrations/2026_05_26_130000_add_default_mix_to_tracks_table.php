<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            // Owner-saved mixer state the player initialises to on every visit
            // (both authenticated and shared views): a list of per-channel
            // {level: 0-100, pan: -100..100, muted: bool}. Null means "fall back
            // to all-100, centred, unmuted" — i.e. no saved default.
            $table->json('default_mix')->nullable()->after('channel_labels');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropColumn('default_mix');
        });
    }
};

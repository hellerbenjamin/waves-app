<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropForeign(['parent_track_id']);
            $table->dropColumn(['parent_track_id', 'split_proposal']);
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->foreignId('parent_track_id')
                ->nullable()
                ->after('event_id')
                ->constrained('tracks')
                ->nullOnDelete();

            $table->json('split_proposal')->nullable()->after('peaks');
        });
    }
};

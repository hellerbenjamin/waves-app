<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            // Self-FK: a child of a split keeps a pointer back to the source
            // track. Null on parent delete so a child outlives its origin —
            // the original is just where the bytes came from, not an owner.
            $table->foreignId('parent_track_id')
                ->nullable()
                ->after('event_id')
                ->constrained('tracks')
                ->nullOnDelete();

            // Transient: lives on the parent track while the user is staging a
            // split. Carries detection params, the candidate regions (start/end
            // seconds), and a coarse status the UI polls. Cleared on commit or
            // discard. Children, once written, exist as independent rows — this
            // column is never read after that.
            $table->json('split_proposal')->nullable()->after('peaks');
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropForeign(['parent_track_id']);
            $table->dropColumn(['parent_track_id', 'split_proposal']);
        });
    }
};

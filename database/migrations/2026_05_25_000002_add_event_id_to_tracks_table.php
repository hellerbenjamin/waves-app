<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            // A track lives in at most one event (a folder). Cross-event
            // playlists are a separate future concern handled by tags, not this
            // column — keep them distinct relationships. Nulling on delete keeps
            // the track in the library when its event is removed.
            $table->foreignId('event_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->index(['event_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tracks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_id');
        });
    }
};

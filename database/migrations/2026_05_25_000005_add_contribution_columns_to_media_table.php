<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Attribution only: the row still belongs to the event owner. Null
            // for the owner's own uploads; set the invite null (not the row) if
            // the link is later deleted, so the media stays.
            $table->foreignId('event_invite_id')->nullable()->after('event_id')->constrained()->nullOnDelete();
            // Free-text name the contributor typed; never trusted for auth.
            $table->string('contributor_name')->nullable()->after('event_invite_id');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropConstrainedForeignId('event_invite_id');
            $table->dropColumn('contributor_name');
        });
    }
};

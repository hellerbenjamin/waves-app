<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Polymorphic membership: a collection gathers tracks and media pulled from
    // any event (or none). Kept deliberately separate from tracks.event_id /
    // media.event_id — events are folders, collections are cross-event curation.
    public function up(): void
    {
        Schema::create('collectables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->morphs('collectable');
            // Reserved for manual playlist ordering; unused for now (items render
            // by insertion order).
            $table->unsignedInteger('position')->nullable();
            $table->timestamps();

            $table->unique(['collection_id', 'collectable_type', 'collectable_id'], 'collectables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collectables');
    }
};

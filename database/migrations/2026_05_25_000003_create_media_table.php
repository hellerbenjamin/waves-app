<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Media survives its event being deleted, mirroring tracks.
            $table->foreignId('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('s3_key')->unique();
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            // 'image' or 'video' — derived from mime at upload, stored for filtering.
            $table->string('kind');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            // Key of the generated thumbnail (images only); null until the job runs.
            $table->string('thumb_key')->nullable();
            $table->string('share_token')->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};

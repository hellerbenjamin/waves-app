<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            // Who minted the link; gone with the user, like the rest of their data.
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            // The unguessable write-token — this row's whole reason to exist.
            $table->string('token')->unique();
            // Owner-facing name for the link, e.g. "Band" or "Audience".
            $table->string('label')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Soft kill-switch: a revoked link 410s without losing its history.
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('uploads_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_invites');
    }
};

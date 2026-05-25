<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            // Free-form-but-validated kind: live_show, rehearsal, studio_session, other.
            $table->string('type')->default('other');
            $table->date('event_date')->nullable();
            $table->string('location')->nullable();
            $table->text('description')->nullable();
            $table->string('share_token')->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'event_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};

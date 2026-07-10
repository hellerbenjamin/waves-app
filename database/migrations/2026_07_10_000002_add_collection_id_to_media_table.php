<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            // Media survives its collection being deleted, mirroring events —
            // collections are curated folders, not owners of the file.
            $table->foreignId('collection_id')->nullable()->after('event_id')->constrained()->nullOnDelete();
            $table->index(['collection_id']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropForeign(['collection_id']);
            $table->dropIndex(['collection_id']);
            $table->dropColumn('collection_id');
        });
    }
};

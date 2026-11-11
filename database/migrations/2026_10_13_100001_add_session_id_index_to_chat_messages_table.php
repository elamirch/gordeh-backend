<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serves "latest message id of a session" (polled every few seconds by guests) and
        // ordered history reads.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->index(['chat_session_id', 'id']);
        });
    }

    public function down(): void
    {
        // On MySQL the FK adopts this index as its supporting index (its own single-column one
        // is dropped as redundant), so the FK must be released before the index can go.
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['chat_session_id']);
            $table->dropIndex(['chat_session_id', 'id']);
        });

        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreign('chat_session_id')->references('id')->on('chat_sessions')->cascadeOnDelete();
        });
    }
};

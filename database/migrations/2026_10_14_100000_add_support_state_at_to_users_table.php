<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // When support_state was last written. The console re-sends `online` as a heartbeat, so
            // an `online` state older than support.chat.presence_ttl_seconds means the tab is gone.
            $table->timestamp('support_state_at')->nullable()->after('support_state');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('support_state_at');
        });
    }
};

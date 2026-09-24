<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Presence for support agents. Manually set (no heartbeat/websocket infra exists to
            // derive this automatically) — defaults to 'offline' until an agent sets it themselves.
            $table->string('support_state')->nullable()->default('offline')->after('role'); // 'online' | 'in_call' | 'break' | 'offline'
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('support_state');
        });
    }
};

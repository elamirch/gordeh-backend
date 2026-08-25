<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Real (already-migrated) databases still have the original narrow
        // enum('active','closed') default 'active' column — widened in place rather than
        // via a rollback, since this table already holds real session/message history.
        // Fresh installs (and the sqlite test DB, rebuilt from scratch every run) already get
        // the correct shape straight from the edited create_chat_sessions_table migration, so
        // there's nothing to alter there.
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE chat_sessions MODIFY status VARCHAR(20) NOT NULL DEFAULT 'waiting'");

            // One-time backfill: every pre-existing row was created with the 'active' default
            // regardless of whether an agent had actually replied yet. Reclassify any 'active'
            // session with no agent-authored message as 'waiting' under the new three-state
            // definition, where 'active' specifically means "an agent has replied at least once".
            DB::table('chat_sessions')
                ->where('status', 'active')
                ->whereNotIn('id', function ($query) {
                    $query->select('chat_session_id')
                        ->from('chat_messages')
                        ->where('sender', 'support');
                })
                ->update(['status' => 'waiting']);
        }

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['status']);
        });
    }
};

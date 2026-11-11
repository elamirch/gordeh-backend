<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Guests have no user row. The FK has to be dropped and re-created around the
        // nullable change — MySQL refuses to alter a column that an FK still depends on.
        // Only if present: on a MyISAM table the FK was never created.
        $hasUserFk = collect(Schema::getForeignKeys('chat_sessions'))
            ->contains(fn (array $fk) => $fk['columns'] === ['user_id']);

        if ($hasUserFk) {
            Schema::table('chat_sessions', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('guest_phone', 11)->nullable()->after('user_id');
            $table->string('guest_name', 60)->nullable()->after('guest_phone');
            // sha256 of the guest token; the raw token is shown to the guest once and never stored.
            $table->string('guest_token_hash', 64)->nullable()->unique()->after('guest_name');

            $table->foreignId('agent_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('agent_joined_at')->nullable()->after('agent_id');
            $table->timestamp('agent_typing_until')->nullable()->after('agent_joined_at');
            // Highest user-message id the agent has seen.
            $table->unsignedBigInteger('last_read_message_id')->nullable()->after('agent_typing_until');

            $table->timestamp('closed_at')->nullable()->after('last_read_message_id');
            $table->string('closed_by', 10)->nullable()->after('closed_at'); // 'user' | 'agent' | 'system'

            $table->string('rating', 10)->nullable()->after('closed_by'); // 'great' | 'good' | 'ok' | 'bad'
            $table->text('rating_note')->nullable()->after('rating');
            $table->timestamp('rated_at')->nullable()->after('rating_note');

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropUnique(['guest_token_hash']);
            $table->dropForeign(['agent_id']);
            $table->dropForeign(['user_id']);
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'guest_phone', 'guest_name', 'guest_token_hash',
                'agent_id', 'agent_joined_at', 'agent_typing_until', 'last_read_message_id',
                'closed_at', 'closed_by',
                'rating', 'rating_note', 'rated_at',
            ]);
        });

        // Guest sessions cannot exist once user_id is NOT NULL again (their messages cascade).
        DB::table('chat_sessions')->whereNull('user_id')->delete();

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};

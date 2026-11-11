<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only if present: on a MyISAM table the FK was never created.
        $hasUserFk = collect(Schema::getForeignKeys('callback_requests'))
            ->contains(fn (array $fk) => $fk['columns'] === ['user_id']);

        if ($hasUserFk) {
            Schema::table('callback_requests', function (Blueprint $table) {
                $table->dropForeign(['user_id']);
            });
        }

        Schema::table('callback_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->text('topic')->nullable()->change();
        });

        Schema::table('callback_requests', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();

            $table->string('name', 60)->nullable()->after('user_id');
            $table->string('source', 20)->default('support_page')->after('status'); // 'support_page' | 'chat_widget'
        });
    }

    public function down(): void
    {
        Schema::table('callback_requests', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn(['name', 'source']);
        });

        DB::table('callback_requests')->whereNull('user_id')->delete();

        Schema::table('callback_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->string('topic')->nullable()->change();
        });

        Schema::table('callback_requests', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }
};

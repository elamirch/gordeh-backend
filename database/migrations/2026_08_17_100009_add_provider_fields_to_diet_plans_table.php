<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('diet_plans', function (Blueprint $table) {
            $table->foreignId('provider_id')
                ->nullable()
                ->after('user_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->string('file_url')->nullable()->after('status');
            $table->string('version')->nullable()->after('file_url');
            $table->text('note')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::table('diet_plans', function (Blueprint $table) {
            $table->dropConstrainedForeignId('provider_id');
            $table->dropColumn(['file_url', 'version', 'note']);
        });
    }
};

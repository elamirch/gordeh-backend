<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "missed": the reminder was too late to send as-is (cron or queue worker was down), so it was
     * folded into a single replacement message instead of going out with the rest of the backlog.
     */
    public function up(): void
    {
        Schema::table('scheduled_sms', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'sent', 'failed', 'missed'])
                ->default('pending')
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('scheduled_sms')->where('status', 'missed')->update(['status' => 'failed']);

        Schema::table('scheduled_sms', function (Blueprint $table) {
            $table->enum('status', ['pending', 'processing', 'sent', 'failed'])
                ->default('pending')
                ->change();
        });
    }
};

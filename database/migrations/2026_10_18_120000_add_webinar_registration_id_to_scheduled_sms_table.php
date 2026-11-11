<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Webinar confirmation and reminder SMSs belong to a registration, so removing someone from a
     * webinar drops the reminders they haven't received yet.
     */
    public function up(): void
    {
        Schema::table('scheduled_sms', function (Blueprint $table) {
            $table->foreignId('webinar_registration_id')
                ->nullable()
                ->after('lab_test_id')
                ->constrained()
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('scheduled_sms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('webinar_registration_id');
        });
    }
};

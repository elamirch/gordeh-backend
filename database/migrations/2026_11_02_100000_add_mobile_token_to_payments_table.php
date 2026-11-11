<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            // Payments started from the mobile app carry a one-time token (only its SHA-256 is kept)
            // that opens the payment page on the website.
            $table->string('mobile_token_hash', 64)->nullable()->unique()->after('authority');
            $table->timestamp('mobile_token_expires_at')->nullable()->after('mobile_token_hash');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique(['mobile_token_hash']);
            $table->dropColumn(['mobile_token_hash', 'mobile_token_expires_at']);
        });
    }
};

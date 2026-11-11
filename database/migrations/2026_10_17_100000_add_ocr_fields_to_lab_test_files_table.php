<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_test_files', function (Blueprint $table) {
            // Groups the files sent together in one OCR request.
            $table->uuid('ocr_batch_id')->nullable()->index()->after('size');
            // pending|success|unreadable|missing_fields|failed; null = not uploaded via OCR.
            $table->string('ocr_status')->nullable()->index()->after('ocr_batch_id');
            $table->json('ocr_result')->nullable()->after('ocr_status');
            $table->timestamp('ocr_processed_at')->nullable()->after('ocr_result');
        });
    }

    public function down(): void
    {
        Schema::table('lab_test_files', function (Blueprint $table) {
            $table->dropIndex(['ocr_batch_id']);
            $table->dropIndex(['ocr_status']);
            $table->dropColumn(['ocr_batch_id', 'ocr_status', 'ocr_result', 'ocr_processed_at']);
        });
    }
};

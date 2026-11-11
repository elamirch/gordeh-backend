<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every failed lab-report OCR attempt, so a patient's "I got an error" can be
        // looked up by the reference code shown to them.
        Schema::create('lab_test_ocr_errors', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique(); // e.g. 'OCR-7K3F9Q', shown to the patient
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 10); // 'server' | 'client'
            $table->string('stage', 20); // 'validation' | 'lookup' | 'storage' | 'ocr' | 'exception' | 'client'
            $table->string('error_type', 40);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('detail')->nullable(); // internal reason — admins only, never the patient
            $table->json('context')->nullable(); // file names/sizes/ids, exception class, …
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['stage', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_test_ocr_errors');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scheduled_sms', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            
            $table->foreignId('insurance_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('lab_test_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->string('phone_number', 11);
            $table->string('template', 250);

            $table->string('token', 50)->nullable();
            $table->string('token2', 50)->nullable();
            $table->string('token3', 50)->nullable();

            $table->timestamp('send_at');

            $table->enum('status', [
                'pending',
                'processing',
                'sent',
                'failed'
            ])->default('pending');

            $table->timestamp('sent_at')->nullable();

            $table->text('error')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_sms');
    }
};

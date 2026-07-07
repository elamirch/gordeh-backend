<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->unsignedBigInteger('amount');
            $table->string('authority')->unique();
            $table->unsignedBigInteger('ref_id')
                ->nullable();
            $table->enum('status', [
                'pending',
                'success',
                'failed',
            ])->default('pending');
            $table->string('description')->nullable();
            $table->boolean('is_used_lab_test')->default(false);
            $table->boolean('is_used_insurance')->default(false);
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
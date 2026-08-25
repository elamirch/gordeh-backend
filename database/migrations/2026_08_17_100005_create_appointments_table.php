<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('provider_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->foreignId('consultation_booking_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->date('date');
            $table->string('time');
            $table->enum('status', [
                'requested', 'confirmed', 'done', 'canceled', 'noshow',
            ])->default('requested');
            $table->string('join_url')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};

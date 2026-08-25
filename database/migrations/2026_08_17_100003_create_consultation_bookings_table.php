<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('provider_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('type'); // 'initial' | 'follow'
            $table->date('date');
            $table->string('time');
            $table->string('full_name');
            $table->string('phone_number');
            $table->string('kidney_stage')->nullable();
            $table->text('medications')->nullable();
            $table->json('conditions')->nullable();
            $table->boolean('use_existing_lab')->default(false);
            $table->enum('status', [
                'new', 'waiting', 'called', 'scheduled', 'review', 'sent', 'done',
            ])->default('new');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_bookings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_booking_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('provider_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->unsignedInteger('duration_sec')->default(0);
            $table->string('outcome')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_calls');
    }
};

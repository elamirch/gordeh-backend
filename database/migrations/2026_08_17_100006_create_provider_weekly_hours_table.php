<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_weekly_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')
                ->constrained('users')
                ->cascadeOnDelete();
            // Carbon::dayOfWeek convention: 0 = Sunday ... 6 = Saturday
            $table->unsignedTinyInteger('d');
            $table->boolean('on')->default(false);
            $table->json('slots')->nullable(); // [["09:00","12:00"], ...]
            $table->timestamps();

            $table->unique(['provider_id', 'd']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_weekly_hours');
    }
};

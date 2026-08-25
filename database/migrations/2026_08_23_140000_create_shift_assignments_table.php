<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agent_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // Carbon::dayOfWeek (0=Sunday..6=Saturday), matches ProviderWeeklyHours.d
            $table->string('window'); // 'morning' | 'noon' | 'evening'
            $table->timestamps();

            $table->unique(['agent_id', 'weekday', 'window']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_assignments');
    }
};

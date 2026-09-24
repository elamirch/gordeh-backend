<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->string('group_label');
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('tone')->nullable();
            $table->boolean('unread')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_notifications');
    }
};

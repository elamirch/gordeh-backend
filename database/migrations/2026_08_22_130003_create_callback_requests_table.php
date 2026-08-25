<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('callback_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('phone');
            $table->string('slot'); // 'morning' | 'noon' | 'evening' (admin API exposes this as `window`)
            $table->string('topic')->nullable();
            $table->string('status')->default('now'); // 'now' | 'wait' | 'miss' | 'done'
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('callback_requests');
    }
};

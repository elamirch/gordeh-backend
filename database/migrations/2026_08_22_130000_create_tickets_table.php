<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('subject');
            $table->string('category'); // 'account' | 'payment' | 'lab-test' | 'app' | 'consultation' | 'other'
            $table->string('channel')->default('chat'); // 'chat' | 'email' | 'phone'
            $table->string('priority')->default('normal'); // 'urgent' | 'normal'
            $table->string('status')->default('open'); // 'open' | 'in_progress' | 'waiting_patient' | 'closed'
            $table->timestamp('sla_deadline')->nullable();
            $table->foreignId('agent_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->json('tags')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('priority');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};

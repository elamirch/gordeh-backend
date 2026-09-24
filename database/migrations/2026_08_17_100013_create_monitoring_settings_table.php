<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitoring_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('first_call_hours')->default(24);
            $table->unsignedInteger('assign_hours')->default(4);
            $table->unsignedInteger('plan_hours')->default(72);
            $table->boolean('notify_email')->default(true);
            $table->boolean('notify_sms')->default(true);
            $table->boolean('notify_panel')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitoring_settings');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alert_rules', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            // discriminator consumed by AlertRuleEvaluator: no_first_call | unassigned | plan_not_sent | capacity_over | late_cancellation
            $table->string('type');
            // only used by rule types not already covered by monitoring_settings hour thresholds (capacity_over, late_cancellation)
            $table->unsignedInteger('threshold_value')->nullable();
            $table->enum('tone', ['red', 'orange'])->default('orange');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_rules');
    }
};

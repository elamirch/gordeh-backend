<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_tests', function (Blueprint $table) {
            $table->id();
            $table->string('gender'); // 'm' or 'f'
            $table->integer('age');
            $table->float('gfr');
            $table->float('uacr')->nullable();
            $table->float('calcium');
            $table->float('phosphorous');
            $table->float('albumin')->nullable();
            $table->float('urine_albumin')->nullable();
            $table->float('b_carbonate');
            $table->float('stage');
            $table->float('creatinine')->nullable();
            $table->float('urine_creatinine')->nullable();
            $table->float('risk_2_years')->nullable();
            $table->float('risk_5_years')->nullable();
            $table->float('albumin_creatinine_ratio')->nullable();
            $table->foreignId('user_id')
                ->constrained('users')
                ->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_tests');
    }
};

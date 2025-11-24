<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone_number')->unique();
            $table->string('email')->unique()->nullable();
            $table->integer('height')->nullable();
            $table->integer('weight')->nullable();
            $table->integer('ideal_weight')->nullable();
            $table->float('BMI')->nullable();
            $table->integer('daily_calories')->nullable();
            $table->enum('gender', ['m', 'f'])->nullable();            $table->string('blood_type')->nullable();
            $table->integer('age')->nullable();
            $table->string('profile_img_url')->unique()->nullable();
            $table->integer('otp_code')->nullable();
            $table->dateTime('otp_code_expiration')->nullable();
            $table->text('refresh_token')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('role')->default('user'); // 'user' | 'admin'
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('sessions');
    }
};

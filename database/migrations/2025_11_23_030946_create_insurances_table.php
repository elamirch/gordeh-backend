<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('insurances', function (Blueprint $table) {
            $table->id();
            $table->string('national_code', 10);
            $table->string('first_name', 50)->nullable();
            $table->string('last_name', 50)->nullable();
            $table->string('insurance_type', 50);
            $table->string('identification_code', 10)->nullable();
            $table->enum('status', ['created', 'in_progress', 'completed'])->default('created');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index('national_code');
            $table->index('status');
            $table->index('user_id');

            $table->foreign('user_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('insurance', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
        });

        Schema::dropIfExists('insurance');
    }
};
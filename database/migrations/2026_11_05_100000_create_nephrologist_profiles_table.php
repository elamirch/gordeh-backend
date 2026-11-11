<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nephrologist_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // draft (still filling the form) | pending (submitted) | needs_fix | approved | rejected | suspended
            $table->string('status', 24)->default('draft')->index();
            $table->unsignedTinyInteger('step')->default(1);
            $table->text('review_note')->nullable();
            $table->string('review_doc')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();

            // Identity
            $table->string('national_id', 10)->nullable();
            $table->unsignedSmallInteger('birth_year')->nullable();

            // Professional
            $table->string('council_number')->nullable();
            $table->string('base_specialty')->nullable(); // int | ped
            $table->string('university')->nullable();
            $table->unsignedSmallInteger('graduation_year')->nullable();
            $table->string('city')->nullable();
            $table->string('workplace')->nullable();
            $table->json('focus')->nullable();
            $table->text('bio')->nullable();
            $table->json('documents')->nullable(); // key => stored_files.id

            // Service
            $table->unsignedBigInteger('fee')->default(0); // IRR
            $table->json('work_days')->nullable(); // [0..6], 0 = Saturday
            $table->string('hours_from', 5)->default('16:00');
            $table->string('hours_to', 5)->default('21:00');
            $table->unsignedTinyInteger('capacity')->default(10);
            $table->boolean('is_accepting')->default(false);
            $table->date('vacation_from')->nullable();
            $table->date('vacation_to')->nullable();

            // Payout
            $table->string('iban', 26)->nullable();
            $table->timestamp('terms_accepted_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nephrologist_profiles');
    }
};

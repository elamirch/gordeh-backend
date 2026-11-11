<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A first run on MySQL failed halfway (the indexes are added after CREATE TABLE), leaving
        // empty tables behind; these tables are new, so start clean.
        Schema::dropIfExists('nephro_payouts');
        Schema::dropIfExists('nephro_reviews');
        Schema::dropIfExists('nephro_messages');
        Schema::dropIfExists('nephro_consultations');

        Schema::create('nephro_consultations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('nephrologist_id')->constrained('users')->cascadeOnDelete();

            // awaiting_reply | active | ended | refunded
            $table->string('status', 24)->default('awaiting_reply');
            $table->string('refund_reason')->nullable(); // no_reply | patient_cancelled | doctor_declined
            $table->string('ended_by')->nullable(); // doctor | system

            // Who the consultation is for
            $table->string('patient_name');
            $table->unsignedSmallInteger('patient_birth_year')->nullable();
            $table->string('patient_sex', 1)->nullable(); // m | f
            $table->string('relation')->default('self'); // self | parent | spouse | child | other

            // What the patient told the doctor (shared only with this doctor)
            $table->string('reason');
            $table->json('symptoms')->nullable();
            $table->string('symptom_duration')->nullable();
            $table->json('diagnoses')->nullable();
            $table->string('medications')->nullable();
            $table->text('notes')->nullable();
            $table->json('lab_test_ids')->nullable();
            $table->json('file_ids')->nullable();
            $table->timestamp('consent_at');

            // Money (IRR), frozen at payment time
            $table->unsignedBigInteger('fee');
            $table->unsignedTinyInteger('commission_percent');
            $table->unsignedBigInteger('commission_amount');
            $table->unsignedBigInteger('doctor_amount');
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();

            // Timeline
            $table->timestamp('reply_due_at')->nullable();
            $table->timestamp('first_replied_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->json('summary')->nullable(); // {summary, recommendations[], tests[], follow_up}

            // Per-side unread counters
            $table->unsignedInteger('patient_unread')->default(0);
            $table->unsignedInteger('doctor_unread')->default(0);
            $table->timestamp('last_message_at')->nullable();

            $table->timestamps();

            $table->index(['nephrologist_id', 'status']);
            $table->index(['patient_id', 'status']);
            $table->index(['status', 'reply_due_at']);
            $table->index(['status', 'expires_at']);
        });

        Schema::create('nephro_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->constrained('nephro_consultations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_role'); // patient | doctor | system
            $table->string('kind')->default('text'); // text | file | action | lab
            $table->text('body')->nullable();
            $table->foreignId('stored_file_id')->nullable()->constrained('stored_files')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['consultation_id', 'id']);
        });

        Schema::create('nephro_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_id')->unique()->constrained('nephro_consultations')->cascadeOnDelete();
            $table->foreignId('patient_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('nephrologist_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('text')->nullable();
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamps();

            $table->index('nephrologist_id');
        });

        Schema::create('nephro_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('nephrologist_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('amount'); // IRR
            $table->string('iban', 26);
            $table->string('status', 24)->default('requested'); // requested | paid | rejected
            $table->string('reference')->nullable(); // bank tracking code
            $table->text('note')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('processed_at')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['nephrologist_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nephro_payouts');
        Schema::dropIfExists('nephro_reviews');
        Schema::dropIfExists('nephro_messages');
        Schema::dropIfExists('nephro_consultations');
    }
};

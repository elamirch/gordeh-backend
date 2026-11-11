<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The wallet: a cached balance on the user (IRR, like Payment.amount) plus a ledger that
        // explains every change to it. The balance only ever moves together with a ledger row.
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('wallet_balance')->default(0)->after('role');
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // 'credit' | 'debit'
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('balance_after');
            // zarinpal | bazaar | admin | refund (credits); lab_test | insurance | consultation (debits)
            $table->string('source', 30);
            // The idempotency key of a credit (gateway authority / Bazaar token): the same
            // reference can never credit twice.
            $table->string('reference', 250)->nullable();
            $table->string('description', 250)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->unique(['source', 'reference']);
        });

        // What a successful payment is for: 'analysis' is the original one-per-request credit,
        // 'wallet' a top-up, 'consultation' a pre-paid consultation (Bazaar).
        Schema::table('payments', function (Blueprint $table) {
            $table->string('purpose', 20)->default('analysis')->after('description');
            // true = nothing left to spend, so every existing row stays a non-credit for consultations.
            $table->boolean('is_used_consultation')->default(true)->after('is_used_insurance');
        });

        // One quick kidney-risk assessment per account (re-doing it replaces the answers), so it
        // is asked once per phone number rather than once per device.
        Schema::create('quick_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('answers'); // { bp|dm|fam|aki|bmi: yes|no|unsure }
            $table->unsignedTinyInteger('age');
            $table->string('gender', 10); // male | female
            $table->decimal('creatinine', 5, 2)->nullable(); // mg/dL, when entered
            $table->decimal('gfr', 6, 1)->nullable();
            $table->unsignedTinyInteger('risk_level'); // 0 low | 1 medium | 2 high
            $table->string('source', 20)->default('web'); // web | app
            $table->timestamps();

            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_assessments');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'is_used_consultation']);
        });
        Schema::dropIfExists('wallet_transactions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('wallet_balance');
        });
    }
};

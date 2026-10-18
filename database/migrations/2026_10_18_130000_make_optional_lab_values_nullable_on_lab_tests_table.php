<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Calcium, phosphorus and bicarbonate are optional on POST /lab-tests (the risk model falls back
     * to population means), but the columns were NOT NULL, so a test submitted without bicarbonate
     * failed with a 500.
     */
    public function up(): void
    {
        Schema::table('lab_tests', function (Blueprint $table) {
            $table->float('calcium')->nullable()->change();
            $table->float('phosphorous')->nullable()->change();
            $table->float('b_carbonate')->nullable()->change();
        });
    }

    /**
     * Missing values are filled with the same population means LabTestController::generateGfr uses.
     */
    public function down(): void
    {
        DB::table('lab_tests')->whereNull('calcium')->update(['calcium' => 9.35]);
        DB::table('lab_tests')->whereNull('phosphorous')->update(['phosphorous' => 3.93]);
        DB::table('lab_tests')->whereNull('b_carbonate')->update(['b_carbonate' => 25.54]);

        Schema::table('lab_tests', function (Blueprint $table) {
            $table->float('calcium')->nullable(false)->change();
            $table->float('phosphorous')->nullable(false)->change();
            $table->float('b_carbonate')->nullable(false)->change();
        });
    }
};

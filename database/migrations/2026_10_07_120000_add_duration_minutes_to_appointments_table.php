<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->unsignedSmallInteger('duration_minutes')->default(15)->after('time');
        });

        // Appointments that already exist were booked on the old 30-minute grid.
        DB::table('appointments')->update(['duration_minutes' => 30]);
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('duration_minutes');
        });
    }
};

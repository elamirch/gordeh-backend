<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webinar_registrations', function (Blueprint $table) {
            $table->id();
            $table->string('webinar', 60); // slug of the event, e.g. 'kidney-awareness-1405-07'
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 60);
            $table->string('phone', 11);
            $table->string('source', 20)->default('website'); // 'website' | 'admin'
            $table->timestamps();

            $table->unique(['webinar', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webinar_registrations');
    }
};

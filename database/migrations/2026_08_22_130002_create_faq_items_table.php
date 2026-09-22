<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_items', function (Blueprint $table) {
            $table->id();
            $table->string('question');
            $table->text('answer');
            $table->string('category')->nullable();
            $table->string('status', 20)->default('published'); // 'published' | 'draft'
            // Named to avoid the reserved SQL keyword `order`; exposed to the admin API as `order`.
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('views')->nullable();
            $table->unsignedTinyInteger('helpful_pct')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_items');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Browser push subscriptions (laravel-notification-channels/webpush) — one row per
// device/browser a support agent turned notifications on in.
return new class extends Migration
{
    public function up(): void
    {
        // A failed earlier run (index too long) leaves the half-built, empty table behind.
        Schema::dropIfExists('push_subscriptions');

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            // Short type column: morphs() makes it varchar(255), and with utf8mb4 that index
            // exceeds MariaDB's 1000-byte key limit.
            $table->string('subscribable_type', 100);
            $table->unsignedBigInteger('subscribable_id');
            $table->index(['subscribable_type', 'subscribable_id'], 'push_subscriptions_subscribable_morph_idx');
            // 768 (not the package's 1024): the unique key must stay under the 1000-byte limit.
            $table->string('endpoint', 768)
                ->charset('ascii')
                ->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};

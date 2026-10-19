<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * On MySQL with explicit_defaults_for_timestamp off, the table's first TIMESTAMP column without an
     * explicit default (send_at) silently becomes DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP.
     * Every status change then overwrote a reminder's scheduled time with "now" (production lost the
     * schedule of every reminder marked missed). Giving the column an explicit default drops the
     * ON UPDATE clause; send_at is always set explicitly, so the default itself is never used.
     */
    public function up(): void
    {
        Schema::table('scheduled_sms', function (Blueprint $table) {
            $table->timestamp('send_at')->useCurrent()->change();
        });
    }

    /**
     * Not reverted: bringing back the auto-update would corrupt send_at again.
     */
    public function down(): void
    {
    }
};

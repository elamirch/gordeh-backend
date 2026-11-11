<?php

use App\Models\FaqItem;
use Illuminate\Database\Migrations\Migration;

/*
| The patient FAQ was empty in production (the seeder is not part of a deploy). Fills it with the
| website's questions plus the ones support hears most — only when there are no items at all, so
| it never overwrites or duplicates what the support team has written since.
*/
return new class extends Migration
{
    public function up(): void
    {
        // Test databases start empty on purpose (RefreshDatabase runs every migration).
        if (app()->runningUnitTests() || FaqItem::query()->exists()) {
            return;
        }

        foreach (require database_path('data/faq.php') as $i => $item) {
            FaqItem::create($item + ['status' => 'published', 'sort_order' => $i + 1]);
        }
    }

    public function down(): void
    {
        // Content, not structure: nothing to undo.
    }
};

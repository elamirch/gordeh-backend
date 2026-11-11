<?php

namespace Database\Seeders;

use App\Models\FaqItem;
use Illuminate\Database\Seeder;

class FaqItemSeeder extends Seeder
{
    public function run(): void
    {
        // An answer an admin has edited is theirs: only missing questions are added.
        foreach (require database_path('data/faq.php') as $i => $item) {
            FaqItem::firstOrCreate(['question' => $item['question']], $item + ['status' => 'published', 'sort_order' => $i + 1]);
        }
    }
}

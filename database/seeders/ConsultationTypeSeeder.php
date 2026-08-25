<?php

namespace Database\Seeders;

use App\Models\ConsultationType;
use Illuminate\Database\Seeder;

class ConsultationTypeSeeder extends Seeder
{
    public function run(): void
    {
        ConsultationType::updateOrCreate(['id' => 'initial'], [
            'title' => 'مشاوره اولیه',
            'description' => 'اولین جلسه مشاوره تغذیه برای ارزیابی وضعیت بیمار',
            'price' => 0,
            'icon' => null,
        ]);

        ConsultationType::updateOrCreate(['id' => 'follow'], [
            'title' => 'مشاوره پیگیری',
            'description' => 'جلسه پیگیری برای بیمارانی که سابقه مشاوره دارند',
            'price' => 0,
            'icon' => null,
        ]);
    }
}

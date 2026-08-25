<?php

namespace Database\Seeders;

use App\Models\AlertRule;
use Illuminate\Database\Seeder;

class AlertRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['type' => 'no_first_call', 'title' => 'درخواست بدون تماس اول', 'tone' => 'red', 'threshold_value' => null],
            ['type' => 'unassigned', 'title' => 'درخواست تخصیص‌نیافته', 'tone' => 'orange', 'threshold_value' => null],
            ['type' => 'plan_not_sent', 'title' => 'مشاوره انجام‌شده بدون ارسال رژیم', 'tone' => 'orange', 'threshold_value' => null],
            ['type' => 'capacity_over', 'title' => 'ظرفیت متخصص بیش از حد پر شده', 'tone' => 'orange', 'threshold_value' => 90],
            ['type' => 'late_cancellation', 'title' => 'لغو نوبت نزدیک به زمان مشاوره', 'tone' => 'red', 'threshold_value' => 6],
        ];

        foreach ($rules as $rule) {
            AlertRule::updateOrCreate(['type' => $rule['type']], $rule);
        }
    }
}

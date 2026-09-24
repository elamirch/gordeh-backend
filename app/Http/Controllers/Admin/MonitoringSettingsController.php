<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MonitoringSetting;
use App\Services\NutritionMonitoring\MonitoringService;
use Illuminate\Http\Request;

class MonitoringSettingsController extends Controller
{
    public function __construct(private readonly MonitoringService $service)
    {
    }

    public function show()
    {
        return response()->json($this->present($this->service->settings()));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'thresholds.firstCallHours' => ['required', 'integer', 'min:1', 'max:999'],
            'thresholds.assignHours' => ['required', 'integer', 'min:1', 'max:999'],
            'thresholds.planHours' => ['required', 'integer', 'min:1', 'max:999'],
            'channels.email' => ['required', 'boolean'],
            'channels.sms' => ['required', 'boolean'],
            'channels.panel' => ['required', 'boolean'],
        ]);

        $settings = $this->service->updateSettings([
            'first_call_hours' => $data['thresholds']['firstCallHours'],
            'assign_hours' => $data['thresholds']['assignHours'],
            'plan_hours' => $data['thresholds']['planHours'],
            'notify_email' => $data['channels']['email'],
            'notify_sms' => $data['channels']['sms'],
            'notify_panel' => $data['channels']['panel'],
        ]);

        return response()->json($this->present($settings));
    }

    private function present(MonitoringSetting $settings): array
    {
        return [
            'thresholds' => [
                'firstCallHours' => $settings->first_call_hours,
                'assignHours' => $settings->assign_hours,
                'planHours' => $settings->plan_hours,
            ],
            'channels' => [
                'email' => $settings->notify_email,
                'sms' => $settings->notify_sms,
                'panel' => $settings->notify_panel,
            ],
        ];
    }
}

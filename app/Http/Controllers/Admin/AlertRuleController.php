<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlertRule;
use App\Services\NutritionMonitoring\MonitoringService;
use Illuminate\Http\Request;

class AlertRuleController extends Controller
{
    public function __construct(private readonly MonitoringService $service)
    {
    }

    public function index()
    {
        return response()->json($this->service->alertRules());
    }

    public function update(Request $request, AlertRule $alertRule)
    {
        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $this->service->updateAlertRule($alertRule, $data['enabled']);

        return response()->json(
            collect($this->service->alertRules())->firstWhere('id', $alertRule->id)
        );
    }

    public function breaches()
    {
        return response()->json($this->service->breaches());
    }
}

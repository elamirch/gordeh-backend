<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsultationBooking;
use App\Models\ProviderNotification;
use App\Services\NutritionMonitoring\MonitoringService;
use Illuminate\Http\Request;

class NutritionMonitoringController extends Controller
{
    public function __construct(private readonly MonitoringService $service)
    {
    }

    public function overview()
    {
        return response()->json($this->service->overview());
    }

    public function queue()
    {
        return response()->json($this->service->queue());
    }

    public function specialists()
    {
        return response()->json($this->service->specialists());
    }

    public function remindQueueItem(ConsultationBooking $consultationBooking)
    {
        $settings = $this->service->settings();
        $notifiedViaPanel = false;

        if ($settings->notify_panel && $consultationBooking->provider_id) {
            ProviderNotification::create([
                'provider_id' => $consultationBooking->provider_id,
                'group_label' => 'یادآوری پایش',
                'title' => "یادآوری برای درخواست REQ-{$consultationBooking->id}",
                'subtitle' => $consultationBooking->full_name,
                'tone' => 'orange',
                'unread' => true,
            ]);
            $notifiedViaPanel = true;
        }

        return response()->json(['ok' => true, 'notifiedViaPanel' => $notifiedViaPanel]);
    }

    public function reassignQueueItem(Request $request, ConsultationBooking $consultationBooking)
    {
        $data = $request->validate([
            'providerId' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $consultationBooking->provider_id = $data['providerId'] ?? null;
        $consultationBooking->save();

        AuditLog::record('assignment', $consultationBooking);

        return response()->json($this->service->queueItem($consultationBooking->fresh()));
    }
}

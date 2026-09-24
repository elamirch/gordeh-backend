<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\ProviderNotification;

class NotificationController extends Controller
{
    public function index()
    {
        $notifications = ProviderNotification::where('provider_id', auth()->id())
            ->orderByDesc('created_at')
            ->get();

        $grouped = $notifications->groupBy('group_label')->map(function ($items, $group) {
            return [
                'g' => $group,
                'items' => $items->map(fn (ProviderNotification $n) => [
                    'i' => $n->id,
                    't' => $n->title,
                    's' => $n->subtitle,
                    'w' => $n->created_at->toIso8601String(),
                    'tone' => $n->tone,
                    'unread' => $n->unread,
                ])->values(),
            ];
        })->values();

        return response()->json($grouped);
    }

    public function readAll()
    {
        ProviderNotification::where('provider_id', auth()->id())->update(['unread' => false]);

        return response()->json(['message' => 'ok']);
    }
}

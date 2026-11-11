<?php

namespace App\Http\Controllers\Webinar;

use App\Http\Controllers\Controller;
use App\Services\ReminderScheduler;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class WebinarController extends Controller
{
    // GET /webinars — upcoming first (soonest first), then past ones (latest first)
    public function index(): JsonResponse
    {
        $now = now();

        $webinars = collect(config('webinars'))
            ->map(function (array $webinar, string $slug) use ($now) {
                $startsAt = ReminderScheduler::webinarStartsAt($slug);
                $upcoming = $startsAt ? $startsAt->greaterThan($now->copy()->subHours(2)) : false;

                return [
                    'slug' => $slug,
                    'title' => $webinar['title'] ?? $slug,
                    'badge' => $webinar['badge'] ?? 'وبینار گرده',
                    'summary' => $webinar['summary'] ?? null,
                    'speaker' => $webinar['speaker'] ?? null,
                    'speaker_title' => $webinar['speaker_title'] ?? null,
                    'mode' => $webinar['mode'] ?? 'آنلاین',
                    'banner' => $webinar['banner'] ?? null,
                    'starts_at' => $startsAt?->toIso8601String(),
                    'date_label' => $startsAt ? ReminderScheduler::spokenStart($startsAt->copy()->timezone(ReminderScheduler::TIMEZONE)->format('Y-m-d H:i')) : null,
                    'status' => ! $startsAt ? 'upcoming' : ($startsAt->greaterThan($now) ? 'upcoming' : ($upcoming ? 'live' : 'past')),
                    // Registration is closed once it has begun.
                    'registration_open' => $startsAt ? $startsAt->greaterThan($now) : true,
                ];
            })
            ->values();

        $sorted = $webinars->where('status', '!=', 'past')->sortBy('starts_at')
            ->concat($webinars->where('status', 'past')->sortByDesc('starts_at'))
            ->values();

        return response()->json($sorted);
    }
}

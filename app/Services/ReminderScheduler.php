<?php

namespace App\Services;

use App\Models\Insurance;
use App\Models\LabTest;
use App\Models\ScheduledSMS;
use App\Models\User;
use Carbon\CarbonInterface;

class ReminderScheduler
{
    /**
     * Reminders go out at a fixed local hour instead of whatever time of day the user submitted.
     */
    public const TIMEZONE = 'Asia/Tehran';
    public const SEND_HOUR = 10;

    /**
     * Per CKD stage: days after a lab test until the next assessment is due, and how many days
     * past due the follow-up reminder is sent (30 for low risk, 14 for high risk stages).
     */
    public const ASSESSMENT_PLAN = [
        1 => ['due' => 365, 'follow_up' => 30, 'follow_up_template' => 'cron-assess-reminder-after'],
        2 => ['due' => 182, 'follow_up' => 30, 'follow_up_template' => 'cron-assess-reminder-after'],
        3 => ['due' => 120, 'follow_up' => 14, 'follow_up_template' => 'cron-assess-reminder-after-14d-onlyhigh'],
        4 => ['due' => 60, 'follow_up' => 14, 'follow_up_template' => 'cron-assess-reminder-after-14d-onlyhigh'],
        5 => ['due' => 30, 'follow_up' => 14, 'follow_up_template' => 'cron-assess-reminder-after-14d-onlyhigh'],
    ];

    public const INSURANCE_REMINDERS = [
        7 => 'cron-insurance-reminder-7d',
        14 => 'cron-insurance-reminder-14d',
        25 => 'cron-insurance-reminder-25d',
    ];

    /**
     * Schedules the "7 days before", "due today" and "overdue" reminders for a lab test. A new test
     * supersedes the previous one, so the user's not-yet-sent assessment reminders are dropped first.
     * Reminders whose time has already passed are skipped. Returns how many were scheduled.
     */
    public function scheduleAssessmentReminders(User $user, LabTest $test): int
    {
        $this->cancelAssessmentReminders($user);

        $plan = self::ASSESSMENT_PLAN[$test->stage] ?? self::ASSESSMENT_PLAN[5];
        $dueAt = $this->sendAt($test->created_at ?? now(), $plan['due']);

        $reminders = [
            ['cron-assess-reminder-7d', $dueAt->copy()->subDays(7), Jalali::format($dueAt->copy()->timezone(self::TIMEZONE))],
            ['cron-assess-reminder', $dueAt, null],
            [$plan['follow_up_template'], $dueAt->copy()->addDays($plan['follow_up']), null],
        ];

        $scheduled = 0;
        foreach ($reminders as [$template, $sendAt, $token3]) {
            if ($sendAt->isPast()) {
                continue;
            }

            ScheduledSMS::create([
                'user_id' => $user->id,
                'phone_number' => $user->phone_number,
                'template' => $template,
                'token' => self::nameToken($user->first_name),
                'token2' => $test->stage,
                'token3' => $token3,
                'send_at' => $sendAt,
                'lab_test_id' => $test->id,
            ]);
            $scheduled++;
        }

        return $scheduled;
    }

    public function cancelAssessmentReminders(User $user): int
    {
        return ScheduledSMS::where('user_id', $user->id)
            ->where('template', 'like', 'cron-assess%')
            ->where('status', 'pending')
            ->delete();
    }

    public function scheduleInsuranceReminders(User $user, Insurance $insurance): void
    {
        foreach (self::INSURANCE_REMINDERS as $days => $template) {
            ScheduledSMS::create([
                'user_id' => $user->id,
                'phone_number' => $user->phone_number,
                'template' => $template,
                'token' => self::nameToken($user->first_name),
                'send_at' => $this->sendAt($insurance->created_at ?? now(), $days),
                'insurance_id' => $insurance->id,
            ]);
        }
    }

    /**
     * $days days after $from, at SEND_HOUR local time, returned in UTC for storage.
     */
    public function sendAt(CarbonInterface $from, int $days): CarbonInterface
    {
        return $from->copy()
            ->timezone(self::TIMEZONE)
            ->addDays($days)
            ->setTime(self::SEND_HOUR, 0)
            ->utc();
    }

    /**
     * Kavenegar rejects an empty token and spaces inside token/token2/token3, so a missing name
     * falls back to "کاربر" and spaces become zero-width non-joiners (keeps the name readable).
     */
    public static function nameToken(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return 'کاربر';
        }

        return mb_substr(preg_replace('/\s+/u', "\u{200C}", $name), 0, 50);
    }
}

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
     * A reminder more than this late (cron or queue worker was down) is no longer sent as-is: its text
     * ("due in 7 days", "due today") would be wrong, and a backlog would reach the user all at once.
     */
    public const MISSED_AFTER_HOURS = 12;

    /**
     * Replacement messages are only sent between these local hours; otherwise they wait for SEND_HOUR.
     */
    public const DAYTIME_START_HOUR = 9;
    public const DAYTIME_END_HOUR = 21;

    /**
     * One replacement per kind: all of a user's missed reminders of that kind collapse into it.
     * token = name, token2 = stage, token3 = assessment due date (Jalali).
     */
    public const MISSED_TEMPLATES = [
        'cron-assess' => 'cron-assess-reminder-missed',
        'cron-insurance' => 'cron-insurance-reminder-missed',
    ];

    /**
     * Schedules the "7 days before", "due today" and "overdue" reminders for a lab test. A new test
     * supersedes the previous one, so the user's not-yet-sent assessment reminders are dropped first.
     * Slots already in the past (only when rescheduling an old test) are handed to
     * replaceMissedReminders by gordeh:sms. Returns how many were scheduled.
     */
    public function scheduleAssessmentReminders(User $user, LabTest $test): int
    {
        $this->cancelAssessmentReminders($user);

        $plan = $this->assessmentPlan($test);
        $dueAt = $this->assessmentDueAt($test);

        $reminders = [
            ['cron-assess-reminder-7d', $dueAt->copy()->subDays(7), Jalali::format($dueAt->copy()->timezone(self::TIMEZONE))],
            ['cron-assess-reminder', $dueAt, null],
            [$plan['follow_up_template'], $dueAt->copy()->addDays($plan['follow_up']), null],
        ];

        foreach ($reminders as [$template, $sendAt, $token3]) {
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
        }

        return count($reminders);
    }

    public function assessmentDueAt(LabTest $test): CarbonInterface
    {
        return $this->sendAt($test->created_at ?? now(), $this->assessmentPlan($test)['due']);
    }

    private function assessmentPlan(LabTest $test): array
    {
        return self::ASSESSMENT_PLAN[$test->stage] ?? self::ASSESSMENT_PLAN[5];
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
     * Folds every user's missed reminders into one replacement message per kind, so a backlog never
     * reaches anyone as a burst of stale SMSs. Due-but-still-on-time reminders of the same user and
     * kind are folded in too. Returns how many replacements were scheduled.
     */
    public function replaceMissedReminders(): int
    {
        $groups = ScheduledSMS::where('status', 'pending')
            ->where('send_at', '<', now()->subHours(self::MISSED_AFTER_HOURS))
            ->whereNotIn('template', self::MISSED_TEMPLATES)
            ->get()
            ->groupBy(fn ($sms) => $sms->phone_number . '|' . self::kind($sms->template));

        $replaced = 0;
        foreach ($groups as $group) {
            $sample = $group->first();

            $due = ScheduledSMS::where('status', 'pending')
                ->where('phone_number', $sample->phone_number)
                ->where('template', 'like', self::kind($sample->template) . '%')
                ->whereNotIn('template', self::MISSED_TEMPLATES)
                ->where('send_at', '<=', now())
                ->orderBy('send_at')
                ->get();

            // Claimed atomically, like gordeh:sms does, so rows a concurrent run already took are left alone.
            $claimed = ScheduledSMS::whereIn('id', $due->pluck('id'))
                ->where('status', 'pending')
                ->update(['status' => 'missed']);

            if ($claimed && $this->scheduleReplacement($due->last())) {
                $replaced++;
            }
        }

        return $replaced;
    }

    /**
     * Whether a reminder is too late to send as-is. Replacement messages are never "missed".
     */
    public function isMissed(ScheduledSMS $sms): bool
    {
        return ! in_array($sms->template, self::MISSED_TEMPLATES, true)
            && $sms->send_at->lt(now()->subHours(self::MISSED_AFTER_HOURS));
    }

    /**
     * For a single reminder that turned out to be missed after it was queued (queue backlog).
     */
    public function replaceMissed(ScheduledSMS $sms): void
    {
        $sms->update(['status' => 'missed']);
        $this->scheduleReplacement($sms);
    }

    /**
     * Schedules the replacement for $missed's kind, unless one is already waiting to go out.
     */
    private function scheduleReplacement(ScheduledSMS $missed): bool
    {
        $template = self::MISSED_TEMPLATES[self::kind($missed->template)] ?? null;
        if (! $template) {
            return false;
        }

        $alreadyQueued = ScheduledSMS::where('phone_number', $missed->phone_number)
            ->where('template', $template)
            ->whereIn('status', ['pending', 'processing'])
            ->exists();
        if ($alreadyQueued) {
            return false;
        }

        $test = $missed->lab_test_id ? LabTest::find($missed->lab_test_id) : null;

        ScheduledSMS::create([
            'user_id' => $missed->user_id,
            'phone_number' => $missed->phone_number,
            'template' => $template,
            'token' => $missed->token,
            'token2' => $test?->stage ?? $missed->token2,
            'token3' => $test ? Jalali::format($this->assessmentDueAt($test)->timezone(self::TIMEZONE)) : null,
            'send_at' => $this->nextDaytime(now()),
            'lab_test_id' => $missed->lab_test_id,
            'insurance_id' => $missed->insurance_id,
        ]);

        return true;
    }

    /**
     * $at itself if it falls in the daytime window, otherwise the next SEND_HOUR local time.
     */
    public function nextDaytime(CarbonInterface $at): CarbonInterface
    {
        $local = $at->copy()->timezone(self::TIMEZONE);

        if ($local->hour >= self::DAYTIME_START_HOUR && $local->hour < self::DAYTIME_END_HOUR) {
            return $at->copy()->utc();
        }

        if ($local->hour >= self::DAYTIME_END_HOUR) {
            $local->addDay();
        }

        return $local->setTime(self::SEND_HOUR, 0)->utc();
    }

    /**
     * "cron-assess" or "cron-insurance": the key reminders are grouped by for replacement.
     */
    private static function kind(string $template): string
    {
        foreach (array_keys(self::MISSED_TEMPLATES) as $prefix) {
            if (str_starts_with($template, $prefix)) {
                return $prefix;
            }
        }

        return $template;
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

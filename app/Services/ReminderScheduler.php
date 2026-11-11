<?php

namespace App\Services;

use App\Models\Insurance;
use App\Models\LabTest;
use App\Models\ScheduledSMS;
use App\Models\User;
use App\Models\WebinarRegistration;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

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
     * Reminders that stop making sense sooner than MISSED_AFTER_HOURS, in minutes: a "starts in an
     * hour" text that arrives after the webinar began, or a "today" text that arrives at night.
     */
    public const MISSED_AFTER_MINUTES = [
        'webinar-reminder-start' => 15,
        'webinar-reminder-1h' => 30,
        'webinar-reminder-today' => 360,
    ];

    /**
     * A webinar's SMSs: the day before and the morning of at SEND_HOUR (token = name, token2 = start
     * time "21:00", sent as token10 "ساعت 21"), one hour before and right as it starts (token = name;
     * the link is written in the template, since Kavenegar tokens can't hold a URL).
     */
    public const WEBINAR_REMINDERS = [
        'webinar-reminder-1d',
        'webinar-reminder-today',
        'webinar-reminder-1h',
        'webinar-reminder-start',
    ];

    /**
     * Templates whose token2 is a start time ("21:00"), sent as token10.
     */
    private const TIME_TOKEN_TEMPLATES = ['webinar-reminder-1d', 'webinar-reminder-today'];

    /**
     * Tells a registrant the webinar moved (token = name, token2 = new local start "2026-10-24 21:00",
     * sent as token10 "شنبه 2 آبان ساعت 21"). Scheduled by gordeh:webinar-reminders --notify.
     */
    public const WEBINAR_DATE_CHANGED = 'webinar-date-changed';

    private const WEEKDAYS = ['یکشنبه', 'دوشنبه', 'سه شنبه', 'چهارشنبه', 'پنج شنبه', 'جمعه', 'شنبه'];

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
     * The segments reminders are tracked by in the admin panel: which message of which campaign. The
     * assessment ones are further split by CKD stage (token2).
     */
    public const SEGMENTS = [
        'assess_7d' => ['cron-assess-reminder-7d'],
        'assess_due' => ['cron-assess-reminder'],
        'assess_follow_up_low' => ['cron-assess-reminder-after'],
        'assess_follow_up_high' => ['cron-assess-reminder-after-14d-onlyhigh'],
        'assess_missed' => ['cron-assess-reminder-missed'],
        'insurance_7d' => ['cron-insurance-reminder-7d'],
        'insurance_14d' => ['cron-insurance-reminder-14d'],
        'insurance_25d' => ['cron-insurance-reminder-25d'],
        'insurance_missed' => ['cron-insurance-reminder-missed'],
        'webinar_1d' => ['webinar-reminder-1d'],
        'webinar_today' => ['webinar-reminder-today'],
        'webinar_1h' => ['webinar-reminder-1h'],
        'webinar_start' => ['webinar-reminder-start'],
        'webinar_changed' => [self::WEBINAR_DATE_CHANGED],
        // Sent right away (SendSMS::instant), logged here so they can be tracked too.
        'otp' => ['otp-kcp'],
        'insurance_code' => ['insurance-generated'],
        'support' => ['support-new-ticket', 'support-reply', 'support-ticket-closed', 'support-callback-request'],
        'support_admin' => [
            'support-admin-new-ticket', 'support-admin-patient-message', 'support-admin-callback-request',
            'support-admin-new-insurance',
        ],
        'nutrition_patient' => [
            'nutrition-booking-registered', 'nutrition-appointment-confirmed',
            'nutrition-appointment-canceled', 'nutrition-plan-sent',
        ],
        'nutrition_provider' => [
            'nutrition-provider-new-request', 'nutrition-provider-assigned', 'nutrition-provider-reminder',
            'nutrition-provider-rescheduled', 'nutrition-provider-canceled',
        ],
    ];

    /**
     * Segments of messages sent right away rather than by gordeh:sms. Retrying one later makes no
     * sense (an expired OTP, a "your ticket got a reply" hours late), so the admin page can't.
     */
    public const INSTANT_SEGMENTS = ['otp', 'insurance_code', 'support', 'support_admin', 'nutrition_patient', 'nutrition_provider'];

    public static function segmentOf(string $template): string
    {
        foreach (self::SEGMENTS as $segment => $templates) {
            if (in_array($template, $templates, true)) {
                return $segment;
            }
        }

        return 'other';
    }

    /**
     * Schedules the "7 days before", "due today" and "overdue" reminders for a lab test. A new test
     * supersedes the previous one, so the user's not-yet-sent assessment reminders are dropped first.
     * Slots already in the past (only when rescheduling an old test) are handed to
     * replaceMissedReminders by gordeh:sms. Returns how many were scheduled.
     */
    public function scheduleAssessmentReminders(User $user, LabTest $test): int
    {
        $this->cancelAssessmentReminders($user);
        // The insurance reminders nudge the user to enter a test; once they have, they stop.
        $this->cancelInsuranceReminders($user);

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

    /**
     * Drops the user's not-yet-sent insurance reminders (and a pending "missed" replacement).
     */
    public function cancelInsuranceReminders(User $user): int
    {
        return ScheduledSMS::where('user_id', $user->id)
            ->where('template', 'like', 'cron-insurance-reminder%')
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
     * Schedules the webinar's reminders. Reminders whose time has already passed (registered late)
     * are skipped. Nothing is scheduled for a webinar that isn't in config/webinars.php or has already
     * started. Returns how many SMSs were scheduled.
     */
    public function scheduleWebinarReminders(WebinarRegistration $registration): int
    {
        $startsAt = self::webinarStartsAt($registration->webinar);
        if (! $startsAt || $startsAt->lte(now())) {
            return 0;
        }

        $local = $startsAt->copy()->timezone(self::TIMEZONE);
        $time = $local->format('H:i');
        $morningOf = $local->copy()->setTime(self::SEND_HOUR, 0)->utc();

        $messages = [
            ['webinar-reminder-1d', $morningOf->copy()->subDay(), $time],
            ['webinar-reminder-today', $morningOf, $time],
            ['webinar-reminder-1h', $startsAt->copy()->subHour(), null],
            ['webinar-reminder-start', $startsAt->copy(), null],
        ];
        $messages = array_values(array_filter($messages, fn ($message) => $message[1]->gt(now())));

        foreach ($messages as [$template, $sendAt, $token2]) {
            ScheduledSMS::create([
                'user_id' => $registration->user_id,
                'phone_number' => $registration->phone,
                'template' => $template,
                'token' => self::nameToken($registration->name),
                'token2' => $token2,
                'send_at' => $sendAt,
                'webinar_registration_id' => $registration->id,
            ]);
        }

        return count($messages);
    }

    /**
     * Schedules a "the webinar moved" SMS for the registration, in the daytime window. Does nothing
     * when this registration was already told about this same start time, so the command can be
     * rerun safely. Returns whether one was scheduled.
     */
    public function scheduleWebinarDateChanged(WebinarRegistration $registration): bool
    {
        $startsAt = self::webinarStartsAt($registration->webinar);
        if (! $startsAt || $startsAt->lte(now())) {
            return false;
        }

        $start = $startsAt->copy()->timezone(self::TIMEZONE)->format('Y-m-d H:i');
        $alreadyTold = ScheduledSMS::where('webinar_registration_id', $registration->id)
            ->where('template', self::WEBINAR_DATE_CHANGED)
            ->where('token2', $start)
            ->whereIn('status', ['pending', 'processing', 'sent'])
            ->exists();
        if ($alreadyTold) {
            return false;
        }

        ScheduledSMS::create([
            'user_id' => $registration->user_id,
            'phone_number' => $registration->phone,
            'template' => self::WEBINAR_DATE_CHANGED,
            'token' => self::nameToken($registration->name),
            'token2' => $start,
            'send_at' => $this->nextDaytime(now()),
            'webinar_registration_id' => $registration->id,
        ]);

        return true;
    }

    /**
     * "شنبه 2 آبان ساعت 21" for a local start "2026-10-24 21:00" (minutes as "ساعت 21 و 30"). The
     * weekday is left out when it would push token10 past its 5 spaces.
     */
    public static function spokenStart(string $local): ?string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2} \d{1,2}:\d{2}$/', $local)) {
            return null;
        }

        $at = Carbon::createFromFormat('Y-m-d H:i', $local, self::TIMEZONE);
        $j = Jalali::fromGregorian($at->year, $at->month, $at->day);
        $rest = $j['jd'].' '.Jalali::MONTHS[$j['jm']].' ساعت '.$at->hour.($at->minute ? ' و '.$at->minute : '');
        $full = self::WEEKDAYS[$at->dayOfWeek].' '.$rest;

        return substr_count($full, ' ') <= 5 ? $full : $rest;
    }

    /**
     * Drops a registration's reminders that haven't gone out yet.
     */
    public function cancelWebinarReminders(WebinarRegistration $registration): int
    {
        return ScheduledSMS::where('webinar_registration_id', $registration->id)
            ->whereIn('template', self::WEBINAR_REMINDERS)
            ->where('status', 'pending')
            ->delete();
    }

    /**
     * The webinar's start time in UTC, or null when it isn't configured.
     */
    public static function webinarStartsAt(string $webinar): ?CarbonInterface
    {
        $startsAt = config("webinars.{$webinar}.starts_at");

        return $startsAt ? Carbon::parse($startsAt, self::TIMEZONE)->utc() : null;
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
        $minutes = self::MISSED_AFTER_MINUTES[$sms->template] ?? self::MISSED_AFTER_HOURS * 60;

        return ! in_array($sms->template, self::MISSED_TEMPLATES, true)
            && $sms->send_at->lt(now()->subMinutes($minutes));
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
     * The Kavenegar lookup tokens [token, token2, token3, token10] for a row. token to token3 take
     * letters and digits only, so a value that needs other characters is reshaped and goes in token10
     * (letters, digits and up to 5 spaces): the due date "1405/8/26" as "26 آبان 1405", a webinar
     * start time "21:30" as "ساعت 21 و 30 دقیقه".
     */
    public static function lookupTokens(ScheduledSMS $sms): array
    {
        $name = self::nameToken($sms->token);

        if ($sms->template === self::WEBINAR_DATE_CHANGED) {
            return [$name, null, null, self::spokenStart((string) $sms->token2)];
        }

        if ($date = Jalali::spoken($sms->token3)) {
            return [$name, $sms->token2, null, $date];
        }

        if (in_array($sms->template, self::TIME_TOKEN_TEMPLATES, true)
            && preg_match('/^(\d{1,2}):(\d{2})$/', (string) $sms->token2, $m)) {
            $time = 'ساعت '.(int) $m[1].((int) $m[2] ? ' و '.(int) $m[2].' دقیقه' : '');

            return [$name, null, $sms->token3, $time];
        }

        return [$name, $sms->token2, $sms->token3, null];
    }

    /**
     * Kavenegar only accepts letters and digits in token/token2/token3 (error 431 for spaces,
     * underscores, separators, and the zero-width non-joiners this used to put in place of spaces),
     * so everything else is dropped: "محمد علی" becomes "محمدعلی". An empty name falls back to "کاربر".
     */
    public static function nameToken(?string $name): string
    {
        $name = preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $name);

        if ($name === '') {
            return 'کاربر';
        }

        return mb_substr($name, 0, 50);
    }
}

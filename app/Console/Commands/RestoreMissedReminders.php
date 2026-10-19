<?php

namespace App\Console\Commands;

use App\Models\Insurance;
use App\Models\LabTest;
use App\Models\ScheduledSMS;
use App\Services\ReminderScheduler;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestoreMissedReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gordeh:restore-missed-reminders
                            {--apply : Write the changes (without it, only shows what would change)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recomputes the real send time of reminders marked missed (send_at was overwritten by MySQL ON UPDATE) and puts the ones still in the future back in the queue';

    /**
     * Execute the console command.
     */
    public function handle(ReminderScheduler $scheduler)
    {
        $restored = [];
        $corrected = 0;
        $skipped = 0;

        DB::beginTransaction();

        $missed = ScheduledSMS::where('status', 'missed')
            ->whereNotIn('template', ReminderScheduler::MISSED_TEMPLATES)
            ->get();

        foreach ($missed as $sms) {
            $sendAt = $this->intendedSendAt($sms, $scheduler);
            if (! $sendAt) {
                $skipped++;
                continue;
            }

            if ($sendAt->isFuture()) {
                $sms->update(['status' => 'pending', 'send_at' => $sendAt, 'error' => null]);
                $restored[] = $sms;
            } else {
                // Really missed; only put its real time back so the admin panel shows it.
                $sms->update(['send_at' => $sendAt]);
                $corrected++;
            }
        }

        $this->table(
            ['Name', 'Phone', 'Template', 'Back in the queue for (Tehran)'],
            collect($restored)->map(fn ($sms) => [
                $sms->token,
                $sms->phone_number,
                $sms->template,
                $sms->send_at->copy()->timezone(ReminderScheduler::TIMEZONE)->format('Y-m-d H:i'),
            ])->all()
        );

        // A replacement that hasn't gone out, for someone none of whose reminders of that kind are
        // missed any more, would announce something that isn't late. Listed only: don't retry these.
        $pointless = collect($restored)
            ->groupBy(fn ($sms) => $sms->phone_number.'|'.$this->kind($sms->template))
            ->flatMap(function ($group) {
                $sample = $group->first();
                $kind = $this->kind($sample->template);

                $stillMissed = ScheduledSMS::where('phone_number', $sample->phone_number)
                    ->where('status', 'missed')
                    ->where('template', 'like', $kind.'%')
                    ->exists();

                return $stillMissed ? [] : ScheduledSMS::where('phone_number', $sample->phone_number)
                    ->where('template', ReminderScheduler::MISSED_TEMPLATES[$kind])
                    ->whereIn('status', ['pending', 'failed'])
                    ->get();
            });

        if ($pointless->isNotEmpty()) {
            $this->warn('These replacement messages are no longer needed. Do not retry them (a pending one will still go out):');
            $this->table(
                ['Id', 'Phone', 'Template', 'Status'],
                $pointless->map(fn ($sms) => [$sms->id, $sms->phone_number, $sms->template, $sms->status])->all()
            );
        }

        if ($this->option('apply')) {
            DB::commit();
        } else {
            DB::rollBack();
            $this->warn('Nothing was written. Run again with --apply to save these changes.');
        }

        $this->table(
            ['Back in the queue', 'Really missed (time corrected)', 'Skipped (no source / superseded)'],
            [[count($restored), $corrected, $skipped]]
        );

        return self::SUCCESS;
    }

    /**
     * When the reminder was supposed to go out, recomputed from the insurance request or lab test it
     * belongs to. Null when that's gone, or the test was superseded by a newer one (its reminders
     * would have been dropped anyway).
     */
    private function intendedSendAt(ScheduledSMS $sms, ReminderScheduler $scheduler): ?CarbonInterface
    {
        $insuranceDays = array_search($sms->template, ReminderScheduler::INSURANCE_REMINDERS, true);
        if ($insuranceDays !== false) {
            $insurance = $sms->insurance_id ? Insurance::find($sms->insurance_id) : null;

            return $insurance ? $scheduler->sendAt($insurance->created_at, $insuranceDays) : null;
        }

        $test = $sms->lab_test_id ? LabTest::find($sms->lab_test_id) : null;
        if (! $test || LabTest::where('user_id', $test->user_id)->latest('id')->value('id') !== $test->id) {
            return null;
        }

        $dueAt = $scheduler->assessmentDueAt($test);
        $plan = ReminderScheduler::ASSESSMENT_PLAN[$test->stage] ?? ReminderScheduler::ASSESSMENT_PLAN[5];

        return match ($sms->template) {
            'cron-assess-reminder-7d' => $dueAt->copy()->subDays(7),
            'cron-assess-reminder' => $dueAt,
            $plan['follow_up_template'] => $dueAt->copy()->addDays($plan['follow_up']),
            default => null,
        };
    }

    private function kind(string $template): string
    {
        return str_starts_with($template, 'cron-insurance') ? 'cron-insurance' : 'cron-assess';
    }
}

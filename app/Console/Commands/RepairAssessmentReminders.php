<?php

namespace App\Console\Commands;

use App\Models\LabTest;
use App\Models\ScheduledSMS;
use App\Models\User;
use App\Services\ReminderScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RepairAssessmentReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gordeh:repair-assessment-reminders
                            {--dry-run : Show what would change without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reschedules assessment reminder SMSs that were created with send_at = now (they went out right after the lab test instead of on their due dates)';

    /**
     * Execute the console command.
     */
    public function handle(ReminderScheduler $scheduler)
    {
        $userIds = ScheduledSMS::where('template', 'like', 'cron-assess%')
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id');

        $users = 0;
        $removed = 0;
        $scheduled = 0;

        DB::beginTransaction();

        foreach (User::whereIn('id', $userIds)->cursor() as $user) {
            $latest = LabTest::where('user_id', $user->id)->latest('id')->first();
            if (! $latest) {
                continue;
            }

            // Reminders only follow the user's most recent test; anything still pending for older
            // tests is dropped by scheduleAssessmentReminders, and past-due slots are skipped.
            $removed += ScheduledSMS::where('user_id', $user->id)
                ->where('template', 'like', 'cron-assess%')
                ->where('status', 'pending')
                ->count();
            $scheduled += $scheduler->scheduleAssessmentReminders($user, $latest);
            $users++;
        }

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->warn('Dry run, nothing was written.');
        } else {
            DB::commit();
        }

        $this->table(['Users', 'Pending reminders removed', 'Reminders scheduled'], [[$users, $removed, $scheduled]]);

        return self::SUCCESS;
    }
}

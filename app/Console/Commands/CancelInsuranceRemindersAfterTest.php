<?php

namespace App\Console\Commands;

use App\Models\Insurance;
use App\Models\LabTest;
use App\Models\ScheduledSMS;
use App\Services\ReminderScheduler;
use Illuminate\Console\Command;

class CancelInsuranceRemindersAfterTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gordeh:cancel-insurance-reminders-after-test
                            {--dry-run : Show what would change without writing anything}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drops pending insurance reminder SMSs of users who entered a lab test after their insurance request (entering a test now does this itself)';

    /**
     * Execute the console command.
     */
    public function handle(ReminderScheduler $scheduler)
    {
        $pending = ScheduledSMS::where('template', 'like', 'cron-insurance-reminder%')
            ->where('status', 'pending')
            ->whereNotNull('user_id')
            ->with('user:id,first_name,last_name,phone_number')
            ->get()
            ->groupBy('user_id');

        $rows = [];
        $removed = 0;

        foreach ($pending as $userId => $reminders) {
            $requestedAt = Insurance::where('user_id', $userId)->min('created_at');
            $hasTestSince = $requestedAt
                && LabTest::where('user_id', $userId)->where('created_at', '>=', $requestedAt)->exists();

            if (! $hasTestSince) {
                continue;
            }

            $user = $reminders->first()->user;
            $rows[] = [$userId, trim(($user->first_name ?? '').' '.($user->last_name ?? '')), $user->phone_number, $reminders->count()];

            if (! $this->option('dry-run')) {
                $removed += $scheduler->cancelInsuranceReminders($user);
            }
        }

        $this->table(['user', 'name', 'phone', 'pending insurance reminders'], $rows);

        $this->info($this->option('dry-run')
            ? count($rows).' users would have their insurance reminders dropped (dry run, nothing changed).'
            : count($rows)." users, {$removed} insurance reminders dropped.");

        return self::SUCCESS;
    }
}

<?php

namespace App\Console\Commands;

use App\Models\WebinarRegistration;
use App\Services\ReminderScheduler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ScheduleWebinarReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gordeh:webinar-reminders
                            {webinar : Slug of the webinar in config/webinars.php}
                            {--notify : Also text every registrant that the date changed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Reschedules the reminder SMSs of every registration for a webinar from its configured start time (after a date change, or for registrations made before reminders existed)';

    /**
     * Execute the console command.
     */
    public function handle(ReminderScheduler $scheduler)
    {
        $webinar = $this->argument('webinar');
        $startsAt = ReminderScheduler::webinarStartsAt($webinar);

        if (! $startsAt) {
            $this->error("No starts_at for '{$webinar}' in config/webinars.php.");
            return self::FAILURE;
        }
        if ($startsAt->lte(now())) {
            $this->error("'{$webinar}' has already started, nothing to schedule.");
            return self::FAILURE;
        }

        $registrations = 0;
        $scheduled = 0;
        $notified = 0;
        $notify = (bool) $this->option('notify');

        DB::transaction(function () use ($scheduler, $webinar, $notify, &$registrations, &$scheduled, &$notified) {
            foreach (WebinarRegistration::where('webinar', $webinar)->cursor() as $registration) {
                $scheduler->cancelWebinarReminders($registration);
                $scheduled += $scheduler->scheduleWebinarReminders($registration);
                if ($notify && $scheduler->scheduleWebinarDateChanged($registration)) {
                    $notified++;
                }
                $registrations++;
            }
        });

        $this->info("{$registrations} registrations, {$scheduled} SMSs scheduled for a start at {$startsAt->copy()->timezone(ReminderScheduler::TIMEZONE)->format('Y-m-d H:i')} Tehran.");
        if ($notify) {
            $this->info("{$notified} date-change SMSs scheduled (".ReminderScheduler::WEBINAR_DATE_CHANGED.').');
        }

        return self::SUCCESS;
    }
}

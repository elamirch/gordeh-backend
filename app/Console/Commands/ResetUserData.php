<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ResetUserData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'gordeh:reset-user
                            {user : User id or phone number (09xxxxxxxxx)}
                            {--force : Skip the confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wipes all data of a user and resets them to a freshly registered state (keeps id, phone number and role)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $identifier = $this->argument('user');

        $user = User::where('phone_number', $identifier)
            ->orWhere('id', ctype_digit($identifier) && strlen($identifier) < 11 ? (int) $identifier : 0)
            ->first();

        if (! $user) {
            $this->error("User [{$identifier}] not found.");
            return self::FAILURE;
        }

        $this->info("User #{$user->id} | {$user->phone_number} | role: {$user->role}");

        if (! $this->option('force') && ! $this->confirm('All data of this user will be permanently deleted. Continue?')) {
            $this->line('Aborted.');
            return self::FAILURE;
        }

        // Collect file paths before the rows referencing them are gone; the files are
        // removed only after the transaction commits so a rollback never loses files.
        $files = $this->collectFiles($user->id);

        DB::transaction(function () use ($user) {
            $id = $user->id;

            // Data the user owns as a patient/customer.
            // scheduled_sms rows cascade from insurances / lab_tests; consultation_calls from bookings;
            // ticket_messages from tickets; chat_messages from chat_sessions.
            DB::table('scheduled_sms')->where('user_id', $id)->delete();
            DB::table('lab_test_files')->where('user_id', $id)->delete();
            DB::table('lab_tests')->where('user_id', $id)->delete();
            DB::table('insurances')->where('user_id', $id)->delete();
            DB::table('stored_files')->where('user_id', $id)->delete();
            DB::table('diet_plans')->where('user_id', $id)->delete();
            DB::table('payments')->where('user_id', $id)->delete();
            DB::table('patient_profiles')->where('user_id', $id)->delete();
            DB::table('patient_assessments')->where('user_id', $id)->delete();
            DB::table('appointments')->where('user_id', $id)->delete();
            DB::table('consultation_bookings')->where('user_id', $id)->delete();
            DB::table('tickets')->where('user_id', $id)->delete();
            DB::table('callback_requests')->where('user_id', $id)->delete();
            DB::table('chat_sessions')->where('user_id', $id)->delete();

            // Data the user owns as a provider / support agent.
            DB::table('provider_profiles')->where('user_id', $id)->delete();
            DB::table('provider_weekly_hours')->where('provider_id', $id)->delete();
            DB::table('provider_notifications')->where('provider_id', $id)->delete();
            DB::table('shift_assignments')->where('agent_id', $id)->delete();

            // References on other users' records: detach (same as nullOnDelete) so their data stays intact.
            DB::table('appointments')->where('provider_id', $id)->update(['provider_id' => null]);
            DB::table('consultation_bookings')->where('provider_id', $id)->update(['provider_id' => null]);
            DB::table('consultation_calls')->where('provider_id', $id)->update(['provider_id' => null]);
            DB::table('patient_assessments')->where('provider_id', $id)->update(['provider_id' => null]);
            DB::table('diet_plans')->where('provider_id', $id)->update(['provider_id' => null]);
            DB::table('tickets')->where('agent_id', $id)->update(['agent_id' => null]);
            DB::table('ticket_messages')->where('author_id', $id)->update(['author_id' => null]);
            DB::table('lab_test_files')->where('uploaded_by', $id)->update(['uploaded_by' => null]);

            if (Schema::hasTable('refresh_tokens')) {
                DB::table('refresh_tokens')->where('user_id', $id)->delete();
            }

            // Reset the user row to what a fresh signup looks like; keep id, phone_number and role.
            // last_logout = now invalidates every JWT issued before the reset (CheckLastLogout).
            $user->forceFill([
                'first_name'          => null,
                'last_name'           => null,
                'email'               => null,
                'height'              => null,
                'weight'              => null,
                'ideal_weight'        => null,
                'BMI'                 => null,
                'daily_calories'      => null,
                'gender'              => null,
                'blood_type'          => null,
                'age'                 => null,
                'profile_img_url'     => null,
                'otp_code'            => null,
                'otp_code_expiration' => null,
                'birth_date'          => null,
                'support_state'       => 'offline',
                'last_logout'         => Carbon::now(),
            ])->save();
        });

        $deleted = 0;
        foreach ($files as [$disk, $path]) {
            if (Storage::disk($disk)->exists($path) && Storage::disk($disk)->delete($path)) {
                $deleted++;
            }
        }

        $this->info("User #{$user->id} has been reset. {$deleted} file(s) removed from storage.");

        return self::SUCCESS;
    }

    /**
     * Files on disk that belong to the user, as [disk, path] pairs.
     */
    private function collectFiles(int $userId): array
    {
        $files = DB::table('lab_test_files')
            ->where('user_id', $userId)
            ->get(['disk', 'storage_key'])
            ->map(fn ($f) => [$f->disk, $f->storage_key])
            ->all();

        $publicPaths = DB::table('stored_files')->where('user_id', $userId)->pluck('url')
            ->merge(DB::table('diet_plans')->where('user_id', $userId)->pluck('file_url'))
            ->merge(
                DB::table('chat_messages')
                    ->join('chat_sessions', 'chat_sessions.id', '=', 'chat_messages.chat_session_id')
                    ->where('chat_sessions.user_id', $userId)
                    ->pluck('chat_messages.file_url')
            );

        foreach ($publicPaths as $path) {
            // Only local relative paths; skip empty values and absolute/external URLs.
            if ($path && ! Str::startsWith($path, ['http://', 'https://', '/'])) {
                $files[] = ['public', $path];
            }
        }

        return $files;
    }
}

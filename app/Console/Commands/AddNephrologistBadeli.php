<?php

namespace App\Console\Commands;

use App\Models\NephrologistProfile;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\PrivateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class AddNephrologistBadeli extends Command
{
    protected $signature = 'gordeh:add-nephrologist-badeli {phone : Doctor mobile number, 09xxxxxxxxx} {--fee=650000 : Fee in toman}';

    protected $description = 'Adds Dr. Hamidreza Badeli as an approved nephrologist (safe to re-run; the IBAN is left for the doctor to enter)';

    public function handle(): int
    {
        $phone = (string) $this->argument('phone');
        if (! preg_match('/^09\d{9}$/', $phone)) {
            $this->error('Phone must look like 09123456789.');

            return self::FAILURE;
        }

        $fee = (int) $this->option('fee') * 10; // toman -> IRR
        if ($fee < (int) config('nephrology.fee.min') || $fee > (int) config('nephrology.fee.max')) {
            $this->error('Fee is outside the allowed range.');

            return self::FAILURE;
        }

        $existing = User::where('phone_number', $phone)->first();
        if ($existing && ! in_array($existing->role, ['user', 'nephrologist'], true)) {
            $this->error("That number belongs to a {$existing->role} account; use another number.");

            return self::FAILURE;
        }

        DB::transaction(function () use ($phone, $fee, $existing) {
            $user = $existing ?? new User(['phone_number' => $phone]);
            $user->fill(['role' => 'nephrologist', 'first_name' => 'حمیدرضا', 'last_name' => 'بادلی', 'gender' => 'm'])->save();

            $profile = NephrologistProfile::firstOrNew(['user_id' => $user->id]);
            $documents = $profile->documents ?? [];

            if (empty($documents['photo'])) {
                $name = 'badeli-'.uniqid().'.png';
                Storage::disk(PrivateFiles::DISK)->put("uploads/{$name}", file_get_contents(database_path('seeders/assets/Dr-Badeli.png')));
                $documents['photo'] = StoredFile::create([
                    'url' => "uploads/{$name}", 'fileName' => $name, 'originalFileName' => 'Dr-Badeli.png',
                    'user_id' => $user->id, 'category' => 'nephro-application',
                ])->id;
            }

            $profile->fill([
                'status' => 'approved',
                'approved_at' => $profile->approved_at ?? now(),
                'base_specialty' => 'ped',
                'council_number' => '36876',
                'graduation_year' => 1371,
                'university' => 'دانشگاه علوم پزشکی',
                'focus' => ['نفرولوژی کودکان', 'بیماری مزمن کلیه (CKD)', 'سنگ کلیه'],
                'bio' => 'فوق‌تخصص نفرولوژی کودکان و نوجوانان؛ دارای برد فوق‌تخصصی نفرولوژی و برد تخصصی کودکان.',
                'fee' => $fee,
                'capacity' => $profile->capacity ?: 10,
                'work_days' => [0, 1, 2, 3, 4],
                'hours_from' => '17:00',
                'hours_to' => '22:00',
                'is_accepting' => true,
                'documents' => $documents,
            ])->save();
        });

        $this->info("Dr. Badeli is set up on {$phone}. He should log in with that number; the IBAN must be added in his panel settings before a payout can be requested.");

        return self::SUCCESS;
    }
}

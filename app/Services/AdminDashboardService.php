<?php

namespace App\Services;

use App\Models\LabTest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    /**
     * Return dashboard stats similar to the NestJS service.
     *
     * @return array
     */
    public function getDashboardStats(): array
    {
        // tests last 30 days
        $totalTestsLast30Days = LabTest::whereRaw("created_at >= NOW() - INTERVAL '30 days'")->count();

        // all users with role = user
        $totalUsers = User::where('role', 'user')->count();

        // age distribution (Postgres filters)
        $ageDistributionRaw = DB::table('users')
            ->selectRaw("
                COUNT(*) FILTER (WHERE age < 40 AND role = 'user') AS under40,
                COUNT(*) FILTER (WHERE age BETWEEN 40 AND 60 AND role = 'user') AS between40and60,
                COUNT(*) FILTER (WHERE age > 60 AND role = 'user') AS above60
            ")
            ->first();

        $ageDistribution = [
            'under40' => (int) ($ageDistributionRaw->under40 ?? 0),
            'between40and60' => (int) ($ageDistributionRaw->between40and60 ?? 0),
            'above60' => (int) ($ageDistributionRaw->above60 ?? 0),
        ];

        // every user's last test (Postgres DISTINCT ON creator_id)
        // adjust column names if your lab_tests creator foreign key differs
        $lastTests = DB::select("
            SELECT DISTINCT ON (user_id) id, stage, user_id
            FROM lab_tests
            ORDER BY user_id, created_at DESC
        ");

        // stage distribution from last tests
        $st1 = $st2 = $st3 = $st4 = $st5 = 0;
        foreach ($lastTests as $row) {
            $stage = (int) ($row->stage ?? 0);
            if ($stage === 1) $st1++;
            elseif ($stage === 2) $st2++;
            elseif ($stage === 3) $st3++;
            elseif ($stage === 4) $st4++;
            elseif ($stage === 5) $st5++;
        }

        // tests stage > 3 (count among last tests)
        $testsStageAbove3 = array_reduce($lastTests, function ($carry, $r) {
            return $carry + ((int) ($r->stage ?? 0) > 3 ? 1 : 0);
        }, 0);

        return [
            'totalTestsLast30Days' => $totalTestsLast30Days,
            'totalUsers' => $totalUsers,
            'ageDistribution' => $ageDistribution,
            'stageDistribution' => [
                'st1' => $st1,
                'st2' => $st2,
                'st3' => $st3,
                'st4' => $st4,
                'st5' => $st5,
            ],
            'testsStageAbove3' => $testsStageAbove3,
        ];
    }
}

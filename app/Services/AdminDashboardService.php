<?php

namespace App\Services;

use App\Models\LabTest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminDashboardService
{
    public function getDashboardStats(): array
    {
        // Tests in last 30 days (Carbon is safe)
        $totalTestsLast30Days = LabTest::where('created_at', '>=', now()->subDays(30))->count();

        // Total users with role 'user'
        $totalUsers = User::where('role', 'user')->count();

        // Age distribution (MySQL/MariaDB compatible)
        $ageDistributionRaw = DB::table('users')
            ->selectRaw("
                SUM(CASE WHEN age < 40 THEN 1 ELSE 0 END) AS under40,
                SUM(CASE WHEN age BETWEEN 40 AND 60 THEN 1 ELSE 0 END) AS between40and60,
                SUM(CASE WHEN age > 60 THEN 1 ELSE 0 END) AS above60
            ")
            ->where('role', 'user')
            ->first();

        $ageDistribution = [
            'under40'        => (int) ($ageDistributionRaw->under40 ?? 0),
            'between40and60' => (int) ($ageDistributionRaw->between40and60 ?? 0),
            'above60'        => (int) ($ageDistributionRaw->above60 ?? 0),
        ];

        // Each user's last test (MySQL/MariaDB compatible)
        $lastTests = DB::select("
            SELECT lt.id, lt.stage, lt.user_id
            FROM lab_tests lt
            INNER JOIN (
                SELECT user_id, MAX(created_at) AS max_created
                FROM lab_tests
                GROUP BY user_id
            ) latest ON lt.user_id = latest.user_id AND lt.created_at = latest.max_created
        ");

        // Stage distribution from last tests
        $st1 = $st2 = $st3 = $st4 = $st5 = 0;
        foreach ($lastTests as $row) {
            $stage = (int) ($row->stage ?? 0);
            match ($stage) {
                1 => $st1++,
                2 => $st2++,
                3 => $st3++,
                4 => $st4++,
                5 => $st5++,
                default => null,
            };
        }

        // Tests with stage > 3 among last tests
        $testsStageAbove3 = count(array_filter($lastTests, fn($r) => ((int) ($r->stage ?? 0)) > 3));

        return [
            'totalTestsLast30Days' => $totalTestsLast30Days,
            'totalUsers'           => $totalUsers,
            'ageDistribution'      => $ageDistribution,
            'stageDistribution'    => [
                'st1' => $st1,
                'st2' => $st2,
                'st3' => $st3,
                'st4' => $st4,
                'st5' => $st5,
            ],
            'testsStageAbove3'     => $testsStageAbove3,
        ];
    }
}
<?php

namespace App\Services;

use DateTimeInterface;

class Jalali
{
    /**
     * Convert Gregorian date to Jalali (returns array with keys jy, jm, jd)
     * Implementation adapted from common algorithms — lightweight, no external package.
     */
    public static function fromGregorian(int $g_y, int $g_m, int $g_d): array
    {
        $g_days_in_month = [31,28,31,30,31,30,31,31,30,31,30,31];
        $j_days_in_month = [31,31,31,31,31,31,30,30,30,30,30,29];

        $gy = $g_y-1600;
        $gm = $g_m-1;
        $gd = $g_d-1;

        $g_day_no = 365*$gy + intval(($gy+3)/4) - intval(($gy+99)/100) + intval(($gy+399)/400);
        for ($i=0; $i < $gm; ++$i) $g_day_no += $g_days_in_month[$i];
        if ($gm>1 && (($gy%4==0 && $gy%100!=0) || ($gy%400==0))) $g_day_no++;
        $g_day_no += $gd;

        $j_day_no = $g_day_no - 79;
        $j_np = intval($j_day_no / 12053);
        $j_day_no = $j_day_no % 12053;
        $jy = 979 + 33*$j_np + 4*intval($j_day_no/1461);
        $j_day_no %= 1461;
        if ($j_day_no >= 366) {
            $jy += intval(($j_day_no-1)/365);
            $j_day_no = ($j_day_no-1) % 365;
        }
        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
            $j_day_no -= $j_days_in_month[$i];
        }
        $jm = $i+1;
        $jd = $j_day_no+1;

        return ['jy' => $jy, 'jm' => $jm, 'jd' => $jd];
    }

    /**
     * Format a date as Y/m/d in the Jalali calendar (e.g. 1405/10/9), the shape the SMS templates expect.
     */
    public static function format(DateTimeInterface $date): string
    {
        $j = self::fromGregorian(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j')
        );

        return $j['jy'] . "/" . $j['jm'] . "/" . $j['jd'];
    }
}

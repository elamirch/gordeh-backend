<?php

namespace App\Support;

use Carbon\Carbon;

class JalaliDate
{
    private const PERSIAN_MONTHS = [
        1 => 'فروردین', 2 => 'اردیبهشت', 3 => 'خرداد', 4 => 'تیر', 5 => 'مرداد', 6 => 'شهریور',
        7 => 'مهر', 8 => 'آبان', 9 => 'آذر', 10 => 'دی', 11 => 'بهمن', 12 => 'اسفند',
    ];

    private const PERSIAN_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];

    /** "5 تیر" — day + month name, Persian digits. */
    public static function dayMonth(Carbon $date): string
    {
        [, $jm, $jd] = self::toJalali($date);

        return self::toPersianDigits((string) $jd).' '.self::PERSIAN_MONTHS[$jm];
    }

    /** "امروز — 5 تیر" / "فردا — 6 تیر" / "5 تیر" depending on how close $date is to today. */
    public static function relativeDayMonth(Carbon $date): string
    {
        $today = Carbon::today();
        $target = $date->copy()->startOfDay();

        if ($target->equalTo($today)) {
            return 'امروز — '.self::dayMonth($date);
        }
        if ($target->equalTo($today->copy()->addDay())) {
            return 'فردا — '.self::dayMonth($date);
        }

        return self::dayMonth($date);
    }

    public static function toPersianDigits(string $value): string
    {
        return strtr($value, ['0' => self::PERSIAN_DIGITS[0], '1' => self::PERSIAN_DIGITS[1], '2' => self::PERSIAN_DIGITS[2], '3' => self::PERSIAN_DIGITS[3], '4' => self::PERSIAN_DIGITS[4], '5' => self::PERSIAN_DIGITS[5], '6' => self::PERSIAN_DIGITS[6], '7' => self::PERSIAN_DIGITS[7], '8' => self::PERSIAN_DIGITS[8], '9' => self::PERSIAN_DIGITS[9]]);
    }

    /**
     * Adapted from the same lightweight algorithm already used in LabTestController —
     * kept here as the single shared copy for anything that needs Jalali day/month display.
     *
     * @return array{0:int,1:int,2:int} [jy, jm, jd]
     */
    public static function toJalali(Carbon $date): array
    {
        $g_days_in_month = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        $j_days_in_month = [31, 31, 31, 31, 31, 31, 30, 30, 30, 30, 30, 29];

        $gy = $date->year - 1600;
        $gm = $date->month - 1;
        $gd = $date->day - 1;

        $g_day_no = 365 * $gy + intval(($gy + 3) / 4) - intval(($gy + 99) / 100) + intval(($gy + 399) / 400);
        for ($i = 0; $i < $gm; ++$i) {
            $g_day_no += $g_days_in_month[$i];
        }
        if ($gm > 1 && (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0))) {
            $g_day_no++;
        }
        $g_day_no += $gd;

        $j_day_no = $g_day_no - 79;
        $j_np = intval($j_day_no / 12053);
        $j_day_no = $j_day_no % 12053;
        $jy = 979 + 33 * $j_np + 4 * intval($j_day_no / 1461);
        $j_day_no %= 1461;
        if ($j_day_no >= 366) {
            $jy += intval(($j_day_no - 1) / 365);
            $j_day_no = ($j_day_no - 1) % 365;
        }
        for ($i = 0; $i < 11 && $j_day_no >= $j_days_in_month[$i]; ++$i) {
            $j_day_no -= $j_days_in_month[$i];
        }
        $jm = $i + 1;
        $jd = $j_day_no + 1;

        return [$jy, $jm, $jd];
    }
}

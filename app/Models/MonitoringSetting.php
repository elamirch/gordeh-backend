<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MonitoringSetting extends Model
{
    protected $fillable = [
        'first_call_hours',
        'assign_hours',
        'plan_hours',
        'notify_email',
        'notify_sms',
        'notify_panel',
    ];

    protected $casts = [
        'notify_email' => 'boolean',
        'notify_sms' => 'boolean',
        'notify_panel' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}

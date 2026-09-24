<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderWeeklyHours extends Model
{
    protected $table = 'provider_weekly_hours';

    protected $fillable = [
        'provider_id',
        'd',
        'on',
        'slots',
    ];

    protected $casts = [
        'd' => 'integer',
        'on' => 'boolean',
        'slots' => 'array',
    ];

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AlertRule extends Model
{
    protected $fillable = [
        'title',
        'type',
        'threshold_value',
        'tone',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}

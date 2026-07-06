<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledSms extends Model
{
    protected $fillable = [
        'user_id',
        'phone_number',
        'template',
        'token',
        'token2',
        'token3',
        'send_at',
        'status',
    ];

    protected $casts = [
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];
}
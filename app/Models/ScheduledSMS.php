<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledSMS extends Model
{
    protected $table = 'scheduled_sms';
    
    protected $fillable = [
        'user_id',
        'phone_number',
        'template',
        'token',
        'token2',
        'token3',
        'send_at',
        'status',
        'sent_at',
        'error',
        'insurance_id',
        'lab_test_id',
        'webinar_registration_id',
    ];

    protected $casts = [
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

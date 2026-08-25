<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationBooking extends Model
{
    protected $fillable = [
        'user_id',
        'provider_id',
        'type',
        'date',
        'time',
        'full_name',
        'phone_number',
        'kidney_stage',
        'medications',
        'conditions',
        'use_existing_lab',
        'status',
        'channel',
    ];

    protected $casts = [
        'date' => 'date',
        'conditions' => 'array',
        'use_existing_lab' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function calls()
    {
        return $this->hasMany(ConsultationCall::class);
    }

    public function appointment()
    {
        return $this->hasOne(Appointment::class);
    }
}

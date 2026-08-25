<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    protected $fillable = [
        'user_id',
        'provider_id',
        'consultation_booking_id',
        'date',
        'time',
        'status',
        'join_url',
        'note',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }

    public function booking()
    {
        return $this->belongsTo(ConsultationBooking::class, 'consultation_booking_id');
    }
}

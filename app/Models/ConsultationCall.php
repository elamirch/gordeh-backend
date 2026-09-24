<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationCall extends Model
{
    protected $fillable = [
        'consultation_booking_id',
        'provider_id',
        'duration_sec',
        'outcome',
    ];

    public function booking()
    {
        return $this->belongsTo(ConsultationBooking::class, 'consultation_booking_id');
    }

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}

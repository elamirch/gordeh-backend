<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientProfile extends Model
{
    protected $fillable = [
        'user_id',
        'stage',
        'risk',
        'risk_tone',
        'nutrition',
        'pref',
        'city',
        'note',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PatientAssessment extends Model
{
    protected $fillable = [
        'user_id',
        'provider_id',
        'title',
        'summary',
        'data',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}

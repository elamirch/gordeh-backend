<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NephroReview extends Model
{
    protected $fillable = ['consultation_id', 'patient_id', 'nephrologist_id', 'rating', 'text', 'reply', 'replied_at'];

    protected $casts = ['rating' => 'integer', 'replied_at' => 'datetime'];

    public function consultation()
    {
        return $this->belongsTo(NephroConsultation::class, 'consultation_id');
    }

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }
}

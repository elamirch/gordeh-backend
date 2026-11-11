<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class QuickAssessment extends Model
{
    protected $fillable = [
        'user_id',
        'answers',
        'age',
        'gender',
        'creatinine',
        'gfr',
        'risk_level',
        'source',
    ];

    protected $casts = [
        'answers' => 'array',
        'age' => 'integer',
        'creatinine' => 'float',
        'gfr' => 'float',
        'risk_level' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public const QUESTIONS = ['bp', 'dm', 'fam', 'aki', 'bmi'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

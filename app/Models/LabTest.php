<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LabTest extends Model
{
    use HasFactory;

    protected $table = 'lab_tests';

    protected $fillable = [
        'gender',
        'age',
        'gfr',
        'uacr',
        'calcium',
        'phosphorous',
        'albumin',
        'urine_albumin',
        'b_carbonate',
        'stage',
        'creatinine',
        'urine_creatinine',
        'risk_2_years',
        'risk_5_years',
        'albumin_creatinine_ratio',
        'user_id',
    ];

    protected $casts = [
        'age'                      => 'integer',
        'gfr'                      => 'float',
        'uacr'                     => 'float',
        'calcium'                  => 'float',
        'phosphorous'              => 'float',
        'albumin'                  => 'float',
        'urine_albumin'            => 'float',
        'b_carbonate'              => 'float',
        'stage'                    => 'float',
        'creatinine'               => 'float',
        'urine_creatinine'         => 'float',
        'risk_2_years'             => 'float',
        'risk_5_years'             => 'float',
        'albumin_creatinine_ratio' => 'float',
        'created_at'               => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

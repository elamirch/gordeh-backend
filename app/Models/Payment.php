<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'authority',
        'status',
        'description',
        'ref_id',
        'is_used_lab_test',
        'is_used_insurance',
    ];

    protected $casts = [
        'is_used_lab_test' => 'boolean',
        'is_used_insurance' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
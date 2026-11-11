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
        'is_used_consultation',
        'purpose',
        'mobile_token_hash',
        'mobile_token_expires_at',
    ];

    // Never serialised: the hash identifies the one-time payment-page link.
    protected $hidden = ['mobile_token_hash'];

    protected $casts = [
        'is_used_lab_test' => 'boolean',
        'is_used_insurance' => 'boolean',
        'is_used_consultation' => 'boolean',
        'mobile_token_expires_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
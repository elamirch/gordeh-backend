<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NephroPayout extends Model
{
    protected $fillable = ['nephrologist_id', 'amount', 'iban', 'status', 'reference', 'note', 'requested_at', 'processed_at', 'processed_by'];

    protected $casts = [
        'amount' => 'integer',
        'requested_at' => 'datetime',
        'processed_at' => 'datetime',
    ];

    public function nephrologist()
    {
        return $this->belongsTo(User::class, 'nephrologist_id');
    }
}

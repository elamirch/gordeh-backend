<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftAssignment extends Model
{
    protected $fillable = [
        'agent_id',
        'weekday',
        'window',
    ];

    protected $casts = [
        'weekday' => 'integer',
    ];

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FaqItem extends Model
{
    protected $fillable = [
        'question',
        'answer',
        'category',
        'status',
        'sort_order',
        'views',
        'helpful_pct',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'views' => 'integer',
        'helpful_pct' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}

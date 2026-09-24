<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConsultationType extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'title',
        'description',
        'price',
        'icon',
    ];
}

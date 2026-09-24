<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderNotification extends Model
{
    protected $fillable = [
        'provider_id',
        'group_label',
        'title',
        'subtitle',
        'tone',
        'unread',
    ];

    protected $casts = [
        'unread' => 'boolean',
    ];

    public function provider()
    {
        return $this->belongsTo(User::class, 'provider_id');
    }
}

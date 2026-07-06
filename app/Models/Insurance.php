<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Insurance extends Model
{
    use HasFactory;

    protected $table = 'insurances';

    protected $fillable = [
        'national_code',
        'first_name',
        'last_name',
        'insurance_type',
        'identification_code',
        'status',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * The creator (user) relationship
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}

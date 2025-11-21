<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    use HasFactory;

    protected $table = 'users';

    protected $fillable = [
        'first_name',
        'last_name',
        'phone_number',
        'email',
        'height',
        'weight',
        'ideal_weight',
        'BMI',
        'daily_calories',
        'gender',
        'blood_type',
        'age',
        'profile_img_url',
        'otp_code',
        'otp_code_expiration',
        'refresh_token',
        'birth_date',
        'role',
    ];

    protected $casts = [
        'height'               => 'integer',
        'weight'               => 'integer',
        'ideal_weight'         => 'integer',
        'BMI'                  => 'float',
        'daily_calories'       => 'integer',
        'age'                  => 'integer',
        'otp_code'             => 'integer',
        'otp_code_expiration'  => 'datetime',
        'birth_date'           => 'date',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function labTests()
    {
        return $this->hasMany(Test::class);
    }

    public function storedFiles()
    {
        return $this->hasMany(FileLibrary::class);
    }
}

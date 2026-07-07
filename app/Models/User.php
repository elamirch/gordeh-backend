<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable;

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

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'id'            => $this->id,
            'phoneNumber'   => $this->phone_number,
            'email'         => $this->email,
            'firstName'     => $this->first_name,
            'lastName'      => $this->last_name,
            'profile_img_url' => $this->profile_img_url,
            'role'          => $this->role,
        ];
    }

    public function labTests()
    {
        return $this->hasMany(LabTest::class, 'user_id');
    }

    public function storedFiles()
    {
        return $this->hasMany(StoredFile::class, 'user_id');
    }

    public function dietPlans()
    {
        return $this->hasMany(DietPlan::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}

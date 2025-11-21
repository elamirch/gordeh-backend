<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StoredFile extends Model
{
    use HasFactory;

    protected $table = 'stored_files';

    protected $fillable = [
        'url',
        'fileName',
        'originalFileName',
        'mainImageUrl',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

<?php

namespace App\Models;

use App\Support\PrivateFiles;
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
        'category',
    ];

    protected $appends = ['signed_url'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /** Short-lived URL to view/download the file; `url` stays the stable storage path. */
    public function getSignedUrlAttribute(): ?string
    {
        return PrivateFiles::signedUrl($this->url);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}

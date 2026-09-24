<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabTestFile extends Model
{
    protected $fillable = [
        'user_id',
        'lab_test_id',
        'uploaded_by',
        'disk',
        'storage_key',
        'original_filename',
        'mime_type',
        'size',
    ];

    protected $casts = [
        'size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function labTest()
    {
        return $this->belongsTo(LabTest::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}

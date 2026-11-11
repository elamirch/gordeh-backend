<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabTestFile extends Model
{
    public const OCR_PENDING = 'pending';
    public const OCR_SUCCESS = 'success';
    public const OCR_UNREADABLE = 'unreadable';
    public const OCR_MISSING_FIELDS = 'missing_fields';
    public const OCR_FAILED = 'failed';

    public const OCR_STATUSES = [
        self::OCR_PENDING,
        self::OCR_SUCCESS,
        self::OCR_UNREADABLE,
        self::OCR_MISSING_FIELDS,
        self::OCR_FAILED,
    ];

    protected $fillable = [
        'user_id',
        'lab_test_id',
        'uploaded_by',
        'disk',
        'storage_key',
        'original_filename',
        'mime_type',
        'size',
        'ocr_batch_id',
        'ocr_status',
        'ocr_result',
        'ocr_processed_at',
    ];

    protected $casts = [
        'size' => 'integer',
        'created_at' => 'datetime',
        'ocr_result' => 'array',
        'ocr_processed_at' => 'datetime',
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

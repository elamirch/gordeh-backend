<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LabTestOcrError extends Model
{
    public const SOURCE_SERVER = 'server';
    public const SOURCE_CLIENT = 'client';

    public const STAGE_VALIDATION = 'validation';
    public const STAGE_LOOKUP = 'lookup';
    public const STAGE_STORAGE = 'storage';
    public const STAGE_OCR = 'ocr';
    public const STAGE_EXCEPTION = 'exception';
    public const STAGE_CLIENT = 'client';

    public const STAGES = [
        self::STAGE_VALIDATION,
        self::STAGE_LOOKUP,
        self::STAGE_STORAGE,
        self::STAGE_OCR,
        self::STAGE_EXCEPTION,
        self::STAGE_CLIENT,
    ];

    protected $fillable = [
        'reference',
        'user_id',
        'source',
        'stage',
        'error_type',
        'http_status',
        'detail',
        'context',
        'user_agent',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function present(): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'source' => $this->source,
            'stage' => $this->stage,
            'errorType' => $this->error_type,
            'httpStatus' => $this->http_status,
            'detail' => $this->detail,
            'context' => $this->context,
            'userAgent' => $this->user_agent,
            'createdAt' => $this->created_at?->toIso8601String(),
            'user' => $this->user ? [
                'id' => $this->user->id,
                'first_name' => $this->user->first_name,
                'last_name' => $this->user->last_name,
                'phone_number' => $this->user->phone_number,
            ] : null,
        ];
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AuditLog extends Model
{
    protected $table = 'admin_audit_logs';

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'ip',
    ];

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public static function record(string $action, ?Model $subject = null): self
    {
        return static::create([
            'actor_id' => auth()->id(),
            'action' => $action,
            'subject_type' => $subject ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'ip' => request()->ip(),
        ]);
    }
}

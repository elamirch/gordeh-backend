<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NephroConsultation extends Model
{
    public const OPEN_STATUSES = ['awaiting_reply', 'active'];

    protected $fillable = [
        'patient_id', 'nephrologist_id', 'status', 'refund_reason', 'ended_by',
        'patient_name', 'patient_birth_year', 'patient_sex', 'relation',
        'reason', 'symptoms', 'symptom_duration', 'diagnoses', 'medications', 'notes', 'lab_test_ids', 'file_ids', 'consent_at',
        'fee', 'commission_percent', 'commission_amount', 'doctor_amount', 'wallet_transaction_id',
        'reply_due_at', 'first_replied_at', 'expires_at', 'ended_at', 'refunded_at', 'summary',
        'patient_unread', 'doctor_unread', 'last_message_at',
    ];

    protected $casts = [
        'symptoms' => 'array',
        'diagnoses' => 'array',
        'lab_test_ids' => 'array',
        'file_ids' => 'array',
        'summary' => 'array',
        'fee' => 'integer',
        'commission_amount' => 'integer',
        'doctor_amount' => 'integer',
        'commission_percent' => 'integer',
        'patient_unread' => 'integer',
        'doctor_unread' => 'integer',
        'consent_at' => 'datetime',
        'reply_due_at' => 'datetime',
        'first_replied_at' => 'datetime',
        'expires_at' => 'datetime',
        'ended_at' => 'datetime',
        'refunded_at' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function patient()
    {
        return $this->belongsTo(User::class, 'patient_id');
    }

    public function nephrologist()
    {
        return $this->belongsTo(User::class, 'nephrologist_id');
    }

    public function messages()
    {
        return $this->hasMany(NephroMessage::class, 'consultation_id');
    }

    public function review()
    {
        return $this->hasOne(NephroReview::class, 'consultation_id');
    }

    public function walletTransaction()
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    /** Whether the doctor may still open the shared patient record. */
    public function doctorHasAccess(): bool
    {
        if ($this->status === 'refunded') {
            return false;
        }
        if ($this->isOpen()) {
            return true;
        }

        return $this->ended_at !== null
            && $this->ended_at->gt(now()->subDays((int) config('nephrology.doctor_access_days')));
    }
}

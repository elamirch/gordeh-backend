<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NephrologistProfile extends Model
{
    public const STATUSES = ['draft', 'pending', 'needs_fix', 'approved', 'rejected', 'suspended'];

    protected $fillable = [
        'user_id', 'status', 'step', 'review_note', 'review_doc', 'submitted_at', 'approved_at', 'reviewed_by',
        'national_id', 'birth_year',
        'council_number', 'base_specialty', 'university', 'graduation_year', 'city', 'workplace', 'focus', 'bio', 'documents',
        'fee', 'work_days', 'hours_from', 'hours_to', 'capacity', 'is_accepting', 'vacation_from', 'vacation_to',
        'iban', 'terms_accepted_at',
    ];

    protected $casts = [
        'focus' => 'array',
        'documents' => 'array',
        'work_days' => 'array',
        'fee' => 'integer',
        'capacity' => 'integer',
        'step' => 'integer',
        'is_accepting' => 'boolean',
        'vacation_from' => 'date',
        'vacation_to' => 'date',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'terms_accepted_at' => 'datetime',
    ];

    // The national id and IBAN identify a real person's money — never serialised by accident.
    protected $hidden = ['national_id', 'iban', 'documents'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function onVacation(): bool
    {
        if (! $this->vacation_from || ! $this->vacation_to) {
            return false;
        }

        return now()->between($this->vacation_from->startOfDay(), $this->vacation_to->endOfDay());
    }

    /** Doctors patients may pick: approved, switched on, not on leave. */
    public function scopeBookable($query)
    {
        return $query->where('status', 'approved')
            ->where('is_accepting', true)
            ->where(fn ($q) => $q->whereNull('vacation_from')->orWhereNull('vacation_to')
                ->orWhere('vacation_from', '>', now()->toDateString())
                ->orWhere('vacation_to', '<', now()->toDateString()));
    }
}

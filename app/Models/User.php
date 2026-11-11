<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Support\PrivateFiles;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Illuminate\Notifications\Notifiable;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, HasPushSubscriptions, Notifiable;

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
        'support_state',
        'support_state_at',
    ];

    protected $appends = ['profile_img_signed_url'];

    protected $casts = [
        'height'               => 'integer',
        'weight'               => 'integer',
        'ideal_weight'         => 'integer',
        'BMI'                  => 'float',
        'daily_calories'       => 'integer',
        'age'                  => 'integer',
        'wallet_balance'       => 'integer',
        'otp_code'             => 'integer',
        'otp_code_expiration'  => 'datetime',
        'birth_date'           => 'date',
        'support_state_at'     => 'datetime',
        'created_at'           => 'datetime',
        'updated_at'           => 'datetime',
    ];

    /**
     * Displayable avatar URL. `profile_img_url` remains the stable stored value (send it
     * back unchanged on update); this one is signed and stable within the hour.
     */
    public function getProfileImgSignedUrlAttribute(): ?string
    {
        return PrivateFiles::signedUrl($this->profile_img_url, null);
    }

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

    public function labTestFiles()
    {
        return $this->hasMany(LabTestFile::class, 'user_id');
    }

    public function dietPlans()
    {
        return $this->hasMany(DietPlan::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function patientProfile()
    {
        return $this->hasOne(PatientProfile::class);
    }

    public function patientAssessments()
    {
        return $this->hasMany(PatientAssessment::class);
    }

    public function providerProfile()
    {
        return $this->hasOne(ProviderProfile::class);
    }

    public function nephrologistProfile()
    {
        return $this->hasOne(NephrologistProfile::class);
    }

    public function consultationBookings()
    {
        return $this->hasMany(ConsultationBooking::class);
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }

    public function providerAppointments()
    {
        return $this->hasMany(Appointment::class, 'provider_id');
    }

    public function tickets()
    {
        return $this->hasMany(Ticket::class);
    }

    public function callbackRequests()
    {
        return $this->hasMany(CallbackRequest::class);
    }

    public function chatSessions()
    {
        return $this->hasMany(ChatSession::class);
    }

    public function assignedTickets()
    {
        return $this->hasMany(Ticket::class, 'agent_id');
    }

    public function shiftAssignments()
    {
        return $this->hasMany(ShiftAssignment::class, 'agent_id');
    }

    public function isSupportStaff(): bool
    {
        return in_array($this->role, ['admin', 'support_agent'], true);
    }

    /**
     * Support presence as it really is: `online` only counts while the console is still sending
     * heartbeats (support_state_at fresher than the presence TTL), otherwise the agent is gone.
     */
    public function effectiveSupportState(): string
    {
        $state = $this->support_state ?? 'offline';
        if ($state !== 'online') {
            return $state;
        }

        $fresh = $this->support_state_at !== null
            && $this->support_state_at->gt(now()->subSeconds((int) config('support.chat.presence_ttl_seconds')));

        return $fresh ? 'online' : 'offline';
    }

    /** Staff who are online *now* — the set that makes the support chat "online". */
    public function scopeOnlineSupportStaff($query)
    {
        return $query->whereIn('role', ['admin', 'support_agent'])
            ->where('support_state', 'online')
            ->where('support_state_at', '>', now()->subSeconds((int) config('support.chat.presence_ttl_seconds')));
    }

    /**
     * Patients a provider is allowed to see: those with a consultation request nobody has
     * claimed yet (the shared inbox) or one this provider owns, or with an appointment / diet
     * plan this provider owns. Admins see every patient. The full user list is admin-panel only.
     */
    /**
     * Specialists patients may see and book. Turning a specialist off in the admin panel (e.g. a
     * test account) hides them from booking, availability and SMS. No profile counts as active.
     */
    public function scopeActiveProvider($query)
    {
        return $query->where('role', 'provider')->where(fn ($q) => $q
            ->whereDoesntHave('providerProfile')
            ->orWhereHas('providerProfile', fn ($p) => $p->where('is_active', true)));
    }

    public function scopeVisibleToProvider($query, User $provider)
    {
        $query->where('role', 'user');

        if ($provider->role === 'admin') {
            return $query;
        }

        return $query->where(function ($q) use ($provider) {
            $q->whereHas('consultationBookings', fn ($b) => $b->where(
                fn ($w) => $w->whereNull('provider_id')->orWhere('provider_id', $provider->id)
            ))
                ->orWhereHas('appointments', fn ($a) => $a->where('provider_id', $provider->id))
                ->orWhereHas('dietPlans', fn ($d) => $d->where('provider_id', $provider->id));
        });
    }

    /** Whether this provider may read the given patient's records (files, labs, plans). */
    public function canViewPatient(int $patientId): bool
    {
        return static::visibleToProvider($this)->whereKey($patientId)->exists();
    }
}

<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsultationBooking;
use App\Models\ProviderNotification;
use App\Models\ProviderProfile;
use App\Models\User;
use App\Services\NutritionMonitoring\MonitoringService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NutritionMonitoringController extends Controller
{
    public function __construct(private readonly MonitoringService $service)
    {
    }

    public function overview()
    {
        return response()->json($this->service->overview());
    }

    public function queue()
    {
        return response()->json($this->service->queue());
    }

    public function specialists()
    {
        return response()->json($this->service->specialists());
    }

    public function remindQueueItem(ConsultationBooking $consultationBooking)
    {
        $settings = $this->service->settings();
        $notifiedViaPanel = false;

        if ($settings->notify_panel && $consultationBooking->provider_id) {
            ProviderNotification::create([
                'provider_id' => $consultationBooking->provider_id,
                'group_label' => 'یادآوری پایش',
                'title' => "یادآوری برای درخواست REQ-{$consultationBooking->id}",
                'subtitle' => $consultationBooking->full_name,
                'tone' => 'orange',
                'unread' => true,
            ]);
            $notifiedViaPanel = true;
        }

        return response()->json(['ok' => true, 'notifiedViaPanel' => $notifiedViaPanel]);
    }

    public function reassignQueueItem(Request $request, ConsultationBooking $consultationBooking)
    {
        $data = $request->validate([
            'providerId' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $consultationBooking->provider_id = $data['providerId'] ?? null;
        $consultationBooking->save();

        AuditLog::record('assignment', $consultationBooking);

        return response()->json($this->service->queueItem($consultationBooking->fresh()));
    }

    /**
     * Create a specialist profile for a phone number and promote the user to provider.
     * Existing JWTs keep the old role claim until the user logs in again / refreshes.
     */
    public function storeSpecialist(Request $request)
    {
        $local = $this->normalizePhone((string) $request->input('phoneNumber'));
        $request->merge(['phoneNumber' => $local]);

        $data = $request->validate([
            'phoneNumber' => ['required', 'regex:/^09\d{9}$/'],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'specialty' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'credentials' => ['nullable', 'string', 'max:255'],
            'personalPhone' => ['nullable', 'string', 'max:32'],
            'gordehPhone' => ['nullable', 'string', 'max:32'],
            'capacity' => ['nullable', 'integer', 'min:1'],
        ]);

        $result = DB::transaction(function () use ($data) {
            $user = User::where('phone_number', $data['phoneNumber'])->lockForUpdate()->first();

            if ($user && in_array($user->role, ['admin', 'support_agent'], true)) {
                throw ValidationException::withMessages([
                    'phoneNumber' => ['نقش این کاربر ادمین یا پشتیبان است و قابل تبدیل به متخصص نیست.'],
                ]);
            }

            if ($user && $user->role === 'provider') {
                return null;
            }

            if (! empty($data['email'])) {
                $taken = User::where('email', $data['email'])
                    ->when($user, fn ($q) => $q->where('id', '!=', $user->id))
                    ->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['email' => ['این ایمیل قبلاً ثبت شده است.']]);
                }
            }

            $user ??= new User(['phone_number' => $data['phoneNumber']]);
            $user->first_name = $data['firstName'];
            $user->last_name = $data['lastName'];
            if (! empty($data['email'])) {
                $user->email = $data['email'];
            }
            $user->role = 'provider';
            $user->save();

            $profile = ProviderProfile::updateOrCreate(['user_id' => $user->id], [
                'specialty' => $data['specialty'],
                'credentials' => $data['credentials'] ?? null,
                'personal_phone' => $data['personalPhone'] ?? null,
                'gordeh_phone' => $data['gordehPhone'] ?? null,
                'capacity' => $data['capacity'] ?? 20,
                'is_active' => true,
            ]);

            AuditLog::record('specialist.created', $user);

            return [$user, $profile];
        });

        if ($result === null) {
            return response()->json(['message' => 'این کاربر قبلاً متخصص است.'], 409);
        }

        [$user, $profile] = $result;

        return response()->json([
            'id' => $user->id,
            'name' => trim($user->first_name.' '.$user->last_name),
            'phoneNumber' => '+98'.substr($user->phone_number, 1),
            'role' => $user->role,
            'specialty' => $profile->specialty,
            'email' => $user->email,
            'credentials' => $profile->credentials,
            'capacity' => $profile->capacity,
            'active' => $profile->is_active,
        ], 201);
    }

    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        if (str_starts_with($digits, '98')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        return '0'.$digits;
    }
}

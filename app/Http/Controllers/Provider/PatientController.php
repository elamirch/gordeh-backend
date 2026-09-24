<?php

namespace App\Http\Controllers\Provider;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ConsultationCall;
use App\Models\PatientAssessment;
use App\Models\StoredFile;
use App\Models\User;
use App\Support\JalaliDate;
use Illuminate\Support\Facades\Storage;

class PatientController extends Controller
{
    public function index()
    {
        $patients = User::where('role', 'user')->with('patientProfile')->get();

        return response()->json($patients->map(fn (User $user) => $this->summarize($user))->values());
    }

    public function show(User $user)
    {
        if ($user->role !== 'user') {
            abort(404);
        }

        $user->load('patientProfile');

        AuditLog::record('view_patient_record', $user);

        return response()->json(array_merge($this->summarize($user), [
            'labs' => $this->labs($user),
            'assessments' => $this->assessments($user),
            'files' => $this->files($user),
            'timeline' => $this->timeline($user),
        ]));
    }

    private function summarize(User $user): array
    {
        $profile = $user->patientProfile;
        $latestLab = $user->labTests()->orderByDesc('created_at')->first();
        $lastVisit = $user->appointments()->where('status', 'done')->orderByDesc('date')->first();

        return [
            'id' => $user->id,
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'age' => $user->age,
            'sex' => $user->gender,
            'phone' => $user->phone_number,
            'stage' => $profile->stage ?? $this->stageLabel($latestLab?->stage),
            'risk' => $profile->risk ?? $this->riskLabel($latestLab?->risk_5_years),
            'riskTone' => $profile->risk_tone ?? $this->riskTone($latestLab?->risk_5_years),
            'lastVisit' => $lastVisit ? JalaliDate::dayMonth($lastVisit->date) : null,
            'nutrition' => $profile->nutrition ?? null,
            'pref' => $profile->pref ?? null,
            'city' => $profile->city ?? null,
            'note' => $profile->note ?? null,
            'plans' => $this->plans($user),
        ];
    }

    private function stageLabel(?float $stage): ?string
    {
        return $stage ? 'مرحله '.JalaliDate::toPersianDigits((string) (int) $stage).' بیماری کلیوی' : null;
    }

    private function riskLabel(?float $risk5Years): ?string
    {
        if ($risk5Years === null) {
            return null;
        }

        return match (true) {
            $risk5Years < 10 => 'کم‌خطر',
            $risk5Years < 30 => 'خطر متوسط',
            default => 'پرخطر',
        };
    }

    private function riskTone(?float $risk5Years): ?string
    {
        if ($risk5Years === null) {
            return null;
        }

        return match (true) {
            $risk5Years < 10 => 'green',
            $risk5Years < 30 => 'blue',
            default => 'orange',
        };
    }

    /**
     * Backend only stores gfr/creatinine/calcium/phosphorous/albumin/uacr per test — not the
     * full metric set the original mock displayed (no cystatin-C/potassium/hemoglobin columns
     * exist), so only these six are surfaced. Reference ranges are standard clinical values.
     */
    private function labs(User $user): array
    {
        $tests = $user->labTests()->orderByDesc('created_at')->get();
        $results = [];

        foreach ($tests as $test) {
            $d = JalaliDate::dayMonth($test->created_at);
            $creatinineRef = $test->gender === 'female' ? [0.6, 1.1] : [0.7, 1.3];

            if ($test->creatinine !== null) {
                $results[] = $this->metric('کراتینین', $test->creatinine, 'mg/dL', $creatinineRef, $d);
            }
            if ($test->gfr !== null) {
                $results[] = [
                    'n' => 'eGFR', 'v' => JalaliDate::toPersianDigits((string) $test->gfr), 'u' => 'mL/min',
                    'ref' => '> ۶۰', 'f' => $test->gfr < 60 ? 'low' : 'ok', 'd' => $d,
                ];
            }
            if ($test->uacr !== null || $test->albumin_creatinine_ratio !== null) {
                $uacr = $test->uacr ?? $test->albumin_creatinine_ratio;
                $results[] = [
                    'n' => 'نسبت آلبومین به کراتینین ادرار', 'v' => JalaliDate::toPersianDigits((string) $uacr), 'u' => 'mg/g',
                    'ref' => '< ۳۰', 'f' => $uacr < 30 ? 'ok' : 'high', 'd' => $d,
                ];
            }
            if ($test->phosphorous !== null) {
                $results[] = $this->metric('فسفر', $test->phosphorous, 'mg/dL', [2.5, 4.5], $d);
            }
            if ($test->calcium !== null) {
                $results[] = $this->metric('کلسیم', $test->calcium, 'mg/dL', [8.5, 10.5], $d);
            }
            if ($test->albumin !== null) {
                $results[] = $this->metric('آلبومین خون', $test->albumin, 'g/dL', [3.5, 5.0], $d);
            }
        }

        return $results;
    }

    private function metric(string $name, float $value, string $unit, array $range, string $date): array
    {
        [$low, $high] = $range;
        $flag = $value < $low ? 'low' : ($value > $high ? 'high' : 'ok');
        $refLabel = JalaliDate::toPersianDigits((string) $low).' – '.JalaliDate::toPersianDigits((string) $high);

        return [
            'n' => $name,
            'v' => JalaliDate::toPersianDigits((string) $value),
            'u' => $unit,
            'ref' => $refLabel,
            'f' => $flag,
            'd' => $date,
        ];
    }

    private function assessments(User $user): array
    {
        return PatientAssessment::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PatientAssessment $a) => [
                'd' => JalaliDate::dayMonth($a->created_at),
                't' => $a->title,
                'score' => $a->data['score'] ?? '—',
                'tone' => $a->data['tone'] ?? 'blue',
                'find' => $a->summary ?? '',
            ])->values()->all();
    }

    private const FILE_CATEGORY_LABELS = [
        'nutrition-consultation' => 'مدرک مشاوره',
        'lab' => 'آزمایش',
    ];

    private function files(User $user): array
    {
        return StoredFile::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (StoredFile $f) => [
                'n' => $f->originalFileName ?? $f->fileName,
                't' => self::FILE_CATEGORY_LABELS[$f->category] ?? 'مدرک',
                'd' => JalaliDate::dayMonth($f->created_at),
                'by' => 'بیمار',
                's' => $this->fileSizeLabel($f->url),
            ])->values()->all();
    }

    private function fileSizeLabel(?string $path): string
    {
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return '—';
        }

        $bytes = Storage::disk('public')->size($path);
        if ($bytes >= 1024 * 1024) {
            return JalaliDate::toPersianDigits(number_format($bytes / (1024 * 1024), 1)).' مگابایت';
        }

        return JalaliDate::toPersianDigits((string) round($bytes / 1024)).' کیلوبایت';
    }

    private function plans(User $user): array
    {
        return $user->dietPlans()->orderByDesc('created_at')->get()->map(fn ($plan) => [
            'd' => JalaliDate::dayMonth($plan->created_at),
            'createdAt' => $plan->created_at->toIso8601String(),
            'by' => $plan->provider ? trim(($plan->provider->first_name ?? '').' '.($plan->provider->last_name ?? '')) : null,
            'st' => $plan->status,
            'v' => $plan->version,
            'f' => $plan->file_url,
            'note' => $plan->note,
        ])->values()->all();
    }

    private function timeline(User $user): array
    {
        $events = collect();

        foreach ($user->appointments as $appointment) {
            $events->push([
                'date' => $appointment->date,
                'd' => JalaliDate::dayMonth($appointment->date),
                't' => 'نوبت '.$appointment->status,
                's' => $appointment->time,
            ]);
        }

        foreach ($user->dietPlans as $plan) {
            $events->push([
                'date' => $plan->created_at,
                'd' => JalaliDate::dayMonth($plan->created_at),
                't' => 'برنامه غذایی '.$plan->status,
                's' => $plan->version ? 'نسخه '.$plan->version : '',
            ]);
        }

        foreach ($user->labTests as $lab) {
            $events->push([
                'date' => $lab->created_at,
                'd' => JalaliDate::dayMonth($lab->created_at),
                't' => 'آزمایش جدید ثبت شد',
                's' => $lab->stage ? 'مرحله '.JalaliDate::toPersianDigits((string) (int) $lab->stage) : '',
            ]);
        }

        $calls = ConsultationCall::whereHas('booking', fn ($q) => $q->where('user_id', $user->id))->get();
        foreach ($calls as $call) {
            $events->push([
                'date' => $call->created_at,
                'd' => JalaliDate::dayMonth($call->created_at),
                't' => 'تماس با بیمار',
                's' => $call->outcome ?? '',
            ]);
        }

        return $events->sortByDesc('date')->map(fn ($e) => ['d' => $e['d'], 't' => $e['t'], 's' => $e['s']])->values()->all();
    }
}

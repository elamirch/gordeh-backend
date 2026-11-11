<?php

namespace App\Http\Controllers\Nephrologist;

use App\Http\Controllers\Controller;
use App\Models\NephrologistProfile;
use App\Models\StoredFile;
use App\Support\PrivateFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A doctor's sign-up. They sign in with the normal OTP login (it is just a Gordeh account), then
 * fill this application step by step; every step is saved, so they can leave and come back. An
 * admin reviews it; only an approved application makes them a nephrologist.
 */
class ApplicationController extends Controller
{
    private const EDITABLE = ['draft', 'needs_fix'];

    public function show(Request $request): JsonResponse
    {
        $this->authorizeApplicant();

        return response()->json($this->present($request->user()->nephrologistProfile));
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorizeApplicant();
        $user = $request->user();
        $profile = $user->nephrologistProfile;

        if ($profile && ! in_array($profile->status, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['status' => ['درخواست شما در حال بررسی است و فعلاً قابل ویرایش نیست.']]);
        }

        $data = $request->validate([
            'step' => ['nullable', 'integer', 'between:1,6'],
            'first_name' => ['nullable', 'string', 'max:60'],
            'last_name' => ['nullable', 'string', 'max:60'],
            'gender' => ['nullable', Rule::in(['m', 'f'])],
            'email' => ['nullable', 'email', 'max:120'],
            'national_id' => ['nullable', 'digits:10'],
            'birth_year' => ['nullable', 'integer', 'between:1250,1400'],
            'council_number' => ['nullable', 'string', 'regex:/^\d{3,10}$/'],
            'base_specialty' => ['nullable', Rule::in(['int', 'ped'])],
            'university' => ['nullable', 'string', 'max:120'],
            'graduation_year' => ['nullable', 'integer', 'between:1300,1450'],
            'city' => ['nullable', 'string', 'max:120'],
            'workplace' => ['nullable', 'string', 'max:160'],
            'focus' => ['nullable', 'array', 'max:12'],
            'focus.*' => ['string', Rule::in(config('nephrology.focus'))],
            'bio' => ['nullable', 'string', 'max:400'],
            'fee' => ['nullable', 'integer', 'min:'.config('nephrology.fee.min'), 'max:'.config('nephrology.fee.max')],
            'work_days' => ['nullable', 'array'],
            'work_days.*' => ['integer', 'between:0,6'],
            'hours_from' => ['nullable', 'date_format:H:i'],
            'hours_to' => ['nullable', 'date_format:H:i'],
            'capacity' => ['nullable', 'integer', 'between:1,50'],
            'iban' => ['nullable', 'regex:/^\d{24}$/'],
            'terms' => ['nullable', 'boolean'],
        ]);

        $userFields = array_intersect_key($data, array_flip(['first_name', 'last_name', 'gender', 'email']));
        if ($userFields) {
            $user->update($userFields);
        }

        $fields = array_intersect_key($data, array_flip([
            'step', 'national_id', 'birth_year', 'council_number', 'base_specialty', 'university', 'graduation_year',
            'city', 'workplace', 'focus', 'bio', 'fee', 'work_days', 'hours_from', 'hours_to', 'capacity', 'iban',
        ]));
        if (array_key_exists('terms', $data)) {
            $fields['terms_accepted_at'] = $data['terms'] ? ($profile?->terms_accepted_at ?? now()) : null;
        }

        $profile = NephrologistProfile::updateOrCreate(['user_id' => $user->id], $fields + ($profile ? [] : ['status' => 'draft']));

        return response()->json($this->present($profile->fresh()));
    }

    public function uploadDocument(Request $request, string $key): JsonResponse
    {
        $this->authorizeApplicant();
        abort_unless(array_key_exists($key, config('nephrology.documents')), 404);

        $profile = $request->user()->nephrologistProfile ?? NephrologistProfile::create(['user_id' => $request->user()->id, 'status' => 'draft']);
        if (! in_array($profile->status, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['file' => ['درخواست شما در حال بررسی است و فعلاً قابل ویرایش نیست.']]);
        }

        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:5120']]);
        $uploaded = $request->file('file');

        $file = StoredFile::create([
            'url' => PrivateFiles::store($uploaded, 'uploads'),
            'fileName' => $uploaded->hashName(),
            'originalFileName' => $uploaded->getClientOriginalName(),
            'user_id' => $request->user()->id,
            'category' => 'nephro-application',
        ]);

        $documents = $profile->documents ?? [];
        $old = $documents[$key] ?? null;
        $documents[$key] = $file->id;
        $profile->update(['documents' => $documents]);

        if ($old && $oldFile = StoredFile::where('id', $old)->where('user_id', $request->user()->id)->first()) {
            PrivateFiles::delete($oldFile->url);
            $oldFile->delete();
        }

        return response()->json($this->present($profile->fresh()));
    }

    public function destroyDocument(Request $request, string $key): JsonResponse
    {
        $this->authorizeApplicant();
        $profile = $request->user()->nephrologistProfile;
        abort_unless($profile && in_array($profile->status, self::EDITABLE, true), 404);

        $documents = $profile->documents ?? [];
        if (isset($documents[$key]) && $file = StoredFile::where('id', $documents[$key])->where('user_id', $request->user()->id)->first()) {
            PrivateFiles::delete($file->url);
            $file->delete();
        }
        unset($documents[$key]);
        $profile->update(['documents' => $documents]);

        return response()->json($this->present($profile->fresh()));
    }

    public function submit(Request $request): JsonResponse
    {
        $this->authorizeApplicant();
        $user = $request->user();
        $profile = $user->nephrologistProfile;

        if (! $profile || ! in_array($profile->status, self::EDITABLE, true)) {
            throw ValidationException::withMessages(['status' => ['این درخواست قابل ارسال نیست.']]);
        }

        $missing = [];
        foreach (['first_name' => $user->first_name, 'last_name' => $user->last_name, 'national_id' => $profile->national_id,
            'council_number' => $profile->council_number, 'university' => $profile->university, 'iban' => $profile->iban] as $field => $value) {
            if (! $value) {
                $missing[$field] = ['این مورد را تکمیل کنید.'];
            }
        }
        if (empty($profile->focus)) {
            $missing['focus'] = ['حداقل یک حوزهٔ تمرکز انتخاب کنید.'];
        }
        if ($profile->fee < (int) config('nephrology.fee.min')) {
            $missing['fee'] = ['تعرفهٔ مشاوره را مشخص کنید.'];
        }
        foreach (config('nephrology.documents') as $key => $required) {
            if ($required && empty(($profile->documents ?? [])[$key])) {
                $missing['documents'] = ['مدارک الزامی را بارگذاری کنید.'];
            }
        }
        if (! $profile->terms_accepted_at) {
            $missing['terms'] = ['برای ادامه باید قرارداد همکاری را بپذیرید.'];
        }
        if ($missing) {
            throw ValidationException::withMessages($missing);
        }

        $profile->update(['status' => 'pending', 'submitted_at' => now(), 'step' => 7, 'review_note' => null, 'review_doc' => null]);

        return response()->json($this->present($profile->fresh()));
    }

    private function authorizeApplicant(): void
    {
        // An admin / support / nutrition-specialist account can't double as a doctor.
        abort_unless(in_array(auth()->user()->role, ['user', 'nephrologist'], true), 403, 'این حساب امکان ثبت‌نام به‌عنوان نفرولوژیست را ندارد.');
    }

    private function present(?NephrologistProfile $p): array
    {
        $user = auth()->user();
        $docs = [];
        $stored = $p?->documents ? StoredFile::whereIn('id', array_values($p->documents))->get()->keyBy('id') : collect();
        foreach (config('nephrology.documents') as $key => $required) {
            $file = $stored[($p?->documents ?? [])[$key] ?? 0] ?? null;
            $docs[$key] = [
                'required' => $required,
                'uploaded' => (bool) $file,
                'name' => $file?->originalFileName,
            ];
        }

        return [
            'status' => $p?->status ?? 'none',
            'step' => $p?->step ?? 1,
            'reviewNote' => $p?->review_note,
            'reviewDoc' => $p?->review_doc,
            'submittedAt' => $p?->submitted_at?->toIso8601String(),
            'approvedAt' => $p?->approved_at?->toIso8601String(),
            'fields' => [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'gender' => $user->gender,
                'email' => $user->email,
                'national_id' => $p?->national_id,
                'birth_year' => $p?->birth_year,
                'council_number' => $p?->council_number,
                'base_specialty' => $p?->base_specialty ?? 'int',
                'university' => $p?->university,
                'graduation_year' => $p?->graduation_year,
                'city' => $p?->city,
                'workplace' => $p?->workplace,
                'focus' => $p?->focus ?? [],
                'bio' => $p?->bio,
                'fee' => $p?->fee ?: (int) config('nephrology.fee.default'),
                'work_days' => $p?->work_days ?? [0, 1, 2, 3],
                'hours_from' => $p?->hours_from ?? '16:00',
                'hours_to' => $p?->hours_to ?? '21:00',
                'capacity' => $p?->capacity ?? 10,
                'iban' => $p?->iban,
                'terms' => (bool) $p?->terms_accepted_at,
            ],
            'documents' => $docs,
            'options' => [
                'focus' => config('nephrology.focus'),
                'fee' => config('nephrology.fee'),
                'commissionPercent' => (int) config('nephrology.commission_percent'),
                'replyDeadlineHours' => (int) config('nephrology.reply_deadline_hours'),
                'chatOpenHours' => (int) config('nephrology.chat_open_hours'),
            ],
        ];
    }
}

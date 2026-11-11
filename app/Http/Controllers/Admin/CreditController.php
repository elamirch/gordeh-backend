<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreditController extends Controller
{
    /**
     * GET /admin/credits?page=&limit=&q=
     * Every wallet top-up made by an admin: who got it, how much, who added it, and when.
     */
    public function index(Request $request)
    {
        $request->validate([
            'page' => 'nullable|integer|min:1',
            'limit' => 'nullable|integer|min:1|max:100',
            'q' => 'nullable|string|max:60',
        ]);

        $query = WalletTransaction::with('user:id,first_name,last_name,phone_number')
            ->where('source', 'admin')
            ->where('type', 'credit')
            ->latest('id');

        if ($q = trim((string) $request->query('q'))) {
            $query->whereHas('user', fn ($u) => $u
                ->where('phone_number', 'like', '%'.$q.'%')
                ->orWhere('first_name', 'like', '%'.$q.'%')
                ->orWhere('last_name', 'like', '%'.$q.'%'));
        }

        $page = $query->paginate((int) $request->query('limit', 20));

        // The admin who made each top-up is recorded in the audit log, keyed by transaction id.
        $actors = AuditLog::with('actor:id,first_name,last_name,phone_number')
            ->where('action', 'wallet.credited')
            ->where('subject_type', 'wallet_transaction')
            ->whereIn('subject_id', $page->getCollection()->pluck('id'))
            ->get()
            ->keyBy('subject_id');

        return response()->json([
            'data' => $page->getCollection()->map(function (WalletTransaction $t) use ($actors) {
                $actor = $actors->get($t->id)?->actor;

                return [
                    'id' => $t->id,
                    'user' => $t->user ? [
                        'id' => $t->user->id,
                        'name' => trim($t->user->first_name.' '.$t->user->last_name),
                        'phone_number' => $t->user->phone_number,
                    ] : null,
                    'amount' => $t->amount,
                    'balance_after' => $t->balance_after,
                    'admin' => $actor ? trim($actor->first_name.' '.$actor->last_name) ?: $actor->phone_number : null,
                    'created_at' => $t->created_at?->toIso8601String(),
                ];
            })->values(),
            'count' => $page->total(),
            'summary' => [
                'total_amount' => (int) WalletTransaction::where('source', 'admin')->where('type', 'credit')->sum('amount'),
            ],
        ]);
    }

    /**
     * Grant a user N free credits. A credit is the same success/unused Payment
     * record a Zarinpal verify (or a discount code) produces, so the regular
     * lastPayment() checks consume them exactly like paid ones.
     */
    public function store(Request $request)
    {
        $request->merge(['phoneNumber' => $this->normalizePhone((string) $request->input('phoneNumber'))]);

        $data = $request->validate([
            'phoneNumber' => ['required', 'regex:/^09\d{9}$/'],
            // One-off credits (the original way), and/or money for the user's wallet (IRR).
            'count' => ['nullable', 'integer', 'min:0', 'max:100'],
            'walletAmount' => ['nullable', 'integer', 'min:10000', 'max:1000000000'],
        ]);
        $data['count'] = (int) ($data['count'] ?? 0);
        $walletAmount = (int) ($data['walletAmount'] ?? 0);

        if ($data['count'] < 1 && $walletAmount < 1) {
            return response()->json(['message' => 'تعداد اعتبار یا مبلغ کیف پول را وارد کنید.', 'errors' => ['count' => ['تعداد اعتبار یا مبلغ کیف پول را وارد کنید.']]], 422);
        }

        $user = User::where('phone_number', $data['phoneNumber'])->first();

        if (! $user) {
            return response()->json(['message' => 'کاربری با این شماره موبایل یافت نشد.'], 404);
        }

        DB::transaction(function () use ($user, $data, $walletAmount) {
            if ($walletAmount > 0) {
                $tx = (new WalletService)->credit($user, $walletAmount, 'admin', 'admin-'.(string) Str::uuid(), 'افزایش موجودی توسط ادمین');
                AuditLog::record('wallet.credited', $tx);
            }

            for ($i = 0; $i < $data['count']; $i++) {
                $payment = Payment::create([
                    'user_id' => $user->id,
                    'amount' => 0,
                    'authority' => 'admin-'.(string) Str::uuid(),
                    'status' => 'success',
                    'description' => 'اعتبار افزوده‌شده توسط ادمین',
                    'is_used_lab_test' => false,
                    'is_used_insurance' => false,
                ]);

                AuditLog::record('credit.added', $payment);
            }
        });

        $available = Payment::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('purpose', '!=', 'wallet')
            ->where('is_used_lab_test', false)
            ->count();

        return response()->json([
            'success' => true,
            'message' => 'اعتبار با موفقیت افزوده شد.',
            'userId' => $user->id,
            'added' => $data['count'],
            'availableCredits' => $available,
            'walletAdded' => $walletAmount,
            'walletBalance' => (new WalletService)->balance($user),
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

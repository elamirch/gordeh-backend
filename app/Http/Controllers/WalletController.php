<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    /**
     * GET /wallet — everything the apps need to show the wallet and decide whether an action
     * can go ahead: the balance, what each service costs, the top-up amounts on offer, how many
     * legacy one-off credits are still unspent (they are used before the wallet) and the latest
     * movements.
     */
    public function show(PaymentService $payments): JsonResponse
    {
        $user = auth()->user();

        $unused = fn (string $flag) => Payment::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('purpose', '!=', 'wallet')
            ->where($flag, false)
            ->count();

        return response()->json([
            'balance' => $payments->wallet()->balance($user),
            'prices' => [
                'lab_test' => PaymentService::price('lab_test'),
                'insurance' => PaymentService::price('insurance'),
                'consultation' => PaymentService::price('consultation'),
            ],
            'topups' => config('payments.wallet.topups'),
            'min_topup' => (int) config('payments.wallet.min'),
            'max_topup' => (int) config('payments.wallet.max'),
            'credits' => [
                'lab_test' => $unused('is_used_lab_test'),
                'insurance' => $unused('is_used_insurance'),
                'consultation' => $unused('is_used_consultation'),
            ],
            'transactions' => WalletTransaction::where('user_id', $user->id)->latest('id')->limit(30)->get()
                ->map(fn (WalletTransaction $t) => $this->present($t))->values(),
        ]);
    }

    /**
     * GET /wallet/transactions?page=&limit=
     */
    public function transactions(Request $request): JsonResponse
    {
        $limit = min(max((int) $request->query('limit', 20), 1), 100);
        $page = WalletTransaction::where('user_id', auth()->id())->latest('id')->paginate($limit);

        return response()->json([
            'data' => $page->getCollection()->map(fn (WalletTransaction $t) => $this->present($t))->values(),
            'count' => $page->total(),
        ]);
    }

    /**
     * POST /wallet/topup — starts a gateway payment whose success credits the wallet (the
     * credit happens in /payments/verify, once, keyed by the gateway authority).
     */
    public function topup(Request $request, PaymentService $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer'],
            'callback_url' => ['nullable', 'url'],
        ]);

        return response()->json(
            $payments->requestPayment(auth()->user(), $data['amount'], $data['callback_url'] ?? null, 'wallet')
        );
    }

    private function present(WalletTransaction $t): array
    {
        return [
            'id' => $t->id,
            'type' => $t->type,
            'amount' => $t->amount,
            'balance_after' => $t->balance_after,
            'source' => $t->source,
            'description' => $t->description,
            'created_at' => $t->created_at?->toIso8601String(),
        ];
    }
}

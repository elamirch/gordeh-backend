<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Exception;

class PaymentService
{
    public const SERVICES = ['lab_test', 'insurance', 'consultation'];

    private const SERVICE_FLAGS = [
        'lab_test' => 'is_used_lab_test',
        'insurance' => 'is_used_insurance',
        'consultation' => 'is_used_consultation',
    ];

    private const SERVICE_LABELS = [
        'lab_test' => 'تحلیل آزمایش کلیه',
        'insurance' => 'درخواست نسخه آزمایش',
        'consultation' => 'مشاوره تغذیه',
    ];

    public function __construct(private ?WalletService $wallet = null)
    {
        $this->wallet ??= new WalletService;
    }

    public function wallet(): WalletService
    {
        return $this->wallet;
    }

    public static function price(string $service): int
    {
        return (int) config("payments.prices.{$service}", 0);
    }

    /**
     * Pays for one use of a service. A legacy credit (an unused successful Payment — admin
     * credits, discount codes, Bazaar purchases) is spent first; otherwise the price is taken
     * from the wallet. Throws InsufficientWalletException when neither can cover it.
     *
     * Call inside the DB transaction that saves what is being paid for, so a failure rolls the
     * charge back with it.
     *
     * @return array{via: string, payment_id?: int, transaction_id?: int}
     */
    public function charge(User $user, string $service): array
    {
        $flag = self::SERVICE_FLAGS[$service] ?? throw new \InvalidArgumentException("Unknown service {$service}");

        return DB::transaction(function () use ($user, $service, $flag) {
            $credit = Payment::where('user_id', $user->id)
                ->where('status', 'success')
                ->where('purpose', '!=', 'wallet')
                ->where($flag, false)
                ->latest('created_at')
                ->lockForUpdate()
                ->first();

            if ($credit) {
                $credit->update([$flag => true]);

                return ['via' => 'credit', 'payment_id' => $credit->id];
            }

            $debit = $this->wallet->debit(
                $user,
                self::price($service),
                $service,
                self::SERVICE_LABELS[$service],
            );

            return ['via' => 'wallet', 'transaction_id' => $debit->id];
        });
    }

    /**
     * Whether `charge()` would succeed right now: what the apps use to decide between
     * «go ahead» and «top up first». Never changes anything.
     */
    public function canAfford(User $user, string $service): bool
    {
        $flag = self::SERVICE_FLAGS[$service] ?? throw new \InvalidArgumentException("Unknown service {$service}");

        $hasCredit = Payment::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('purpose', '!=', 'wallet')
            ->where($flag, false)
            ->exists();

        return $hasCredit || $this->wallet->balance($user) >= self::price($service);
    }

    /**
     * Get user payments
     */
    public function getOwnPayments(User $user, int $page = 1, int $limit = 10)
    {
        $limit = min($limit, 100);

        return Payment::where('user_id', $user->id)
            ->latest('id')
            ->paginate($limit, ['*'], 'page', $page);
    }

    /**
     * Create Zarinpal payment request
     */
    public function requestPayment(User $user, int $amount, ?string $callbackUrl = null, string $purpose = 'analysis')
    {
        $description = $this->describePurchase($amount, $purpose);
        $callback = $callbackUrl ?? config('payments.zarinpal.callback_url');

        $authority = $this->zarinpalRequest($user, $amount, $callback, $description);

        $this->createPendingPayment($user, $amount, $authority, $description, $purpose);

        return [
            'message' => 'payment created successfully',
            'url' => self::gatewayUrl($authority),
            'authority' => $authority,
        ];
    }

    /**
     * Validates what a gateway payment would buy and returns its description. Shared by the
     * website and the mobile payment page, so both enforce the same prices and limits.
     */
    private function describePurchase(int $amount, string $purpose): string
    {
        if ($purpose === 'wallet') {
            $min = (int) config('payments.wallet.min');
            $max = (int) config('payments.wallet.max');
            if ($amount < $min || $amount > $max) {
                throw ValidationException::withMessages([
                    'amount' => ['مبلغ افزایش موجودی باید بین '.number_format($min / 10).' و '.number_format($max / 10).' تومان باشد.'],
                ]);
            }

            return 'افزایش موجودی کیف پول';
        }
        if ($purpose === 'consultation') {
            $this->assertPaysForService($amount, 'consultation');

            return 'پرداخت مشاوره تغذیه';
        }
        // One payment is one lab-test analysis AND one insurance request (the original
        // credit), so it must cover the dearer of the two.
        $this->assertPaysForService($amount, 'lab_test');

        return 'پرداخت جهت انجام تست کلیه';
    }

    private static function gatewayUrl(string $authority): string
    {
        return 'https://payment.zarinpal.com/pg/StartPay/'.$authority;
    }

    /** Asks Zarinpal for a payment and returns its authority. */
    private function zarinpalRequest(User $user, int $amount, ?string $callback, string $description): string
    {
        $response = Http::post(
            config('payments.zarinpal.api_url').'request.json',
            [
                'merchant_id' => config('payments.zarinpal.merchant_id'),
                'amount' => $amount,
                'callback_url' => $callback,
                'description' => $description,
                'metadata' => [
                    'mobile' => $user->phone_number,
                ],
            ]
        );

        if (!$response->successful()) {
            throw new Exception('Unable to connect to Zarinpal: ' . $response);
        }

        return $response->json('data')['authority'];
    }

    private function createPendingPayment(User $user, int $amount, string $authority, string $description, string $purpose, array $extra = []): Payment
    {
        return Payment::create($extra + [
            'user_id' => $user->id,
            'amount' => $amount,
            'authority' => $authority,
            'status' => 'pending',
            'description' => $description,
            'purpose' => $purpose,
            // A top-up is money, not a credit: nothing in it is spendable per request.
            'is_used_lab_test' => $purpose !== 'analysis',
            'is_used_insurance' => $purpose !== 'analysis',
            'is_used_consultation' => $purpose !== 'consultation',
        ]);
    }

    /**
     * The gateway amount is chosen by the client, so it is checked against the price: a
     * one-off payment must never buy a credit for less than the service costs.
     */
    private function assertPaysForService(int $amount, string $service): void
    {
        if ($amount < self::price($service)) {
            throw ValidationException::withMessages([
                'amount' => ['مبلغ پرداخت کمتر از هزینه این خدمت است.'],
            ]);
        }
    }

    /**
     * Verify payment
     */
    public function verifyPayment(string $authority)
    {
        $payment = Payment::where('authority', $authority)
            ->with('user')
            ->first();

        if (!$payment) {
            throw new Exception('Payment not found.');
        }

        // Both the web callback page and the app may verify the same authority: a payment
        // that already succeeded is simply reported as such.
        if ($payment->status === 'success') {
            return [
                'message' => 'Payment verified successfully',
                'status' => 'success',
                'ref_id' => $payment->ref_id,
            ];
        }

        if ($payment->status !== 'pending') {
            throw new Exception('Payment already processed.');
        }


        $response = Http::post(
            config('payments.zarinpal.api_url') . 'verify.json',
            [
                'merchant_id' => config('payments.zarinpal.merchant_id'),
                'amount' => $payment->amount,
                'authority' => $authority,
            ]
        );

        if (!$response->successful()) {
            throw new Exception('Verification request failed: ' . $response);
        }

        $data = $response->json('data');

        if (in_array($data['code'], [100, 101])) {

            DB::transaction(function () use ($payment, $data) {
                $payment->update([
                    'status' => 'success',
                    'ref_id' => $data['ref_id'],
                ]);

                // A top-up lands in the wallet exactly once (keyed by the gateway authority).
                if ($payment->purpose === 'wallet') {
                    $this->wallet->credit(
                        $payment->user,
                        (int) $payment->amount,
                        'zarinpal',
                        $payment->authority,
                        'افزایش موجودی از درگاه پرداخت',
                        ['payment_id' => $payment->id, 'ref_id' => $data['ref_id']]
                    );
                }
            });

            return [
                'message' => 'Payment verified successfully',
                'status' => 'success',
                'ref_id' => $data['ref_id'],
            ];
        }

        $payment->update([
            'status' => 'failed',
        ]);

        return [
            'message' => 'Payment failed',
            'status' => 'failed',
            'error_code' => $data['code'],
        ];
    }

    // ---- Mobile payment page ------------------------------------------------------------

    private const MOBILE_PENDING_PREFIX = 'mobile-pending-';

    private static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Starts a payment from the app: records it as pending and issues the one-time token that
     * opens the payment page. The price of a service is decided here; only a wallet top-up
     * takes its (range-checked) amount from the client. Nothing is sent to Zarinpal yet.
     *
     * @return array{payment_id: int, url: string, amount: int, expires_at: string}
     */
    public function initMobilePayment(User $user, string $purpose, ?int $amount = null): array
    {
        if ($purpose === 'consultation') {
            $amount = self::price('consultation');
        } elseif ($purpose === 'analysis') {
            $amount = max(self::price('lab_test'), self::price('insurance'));
        } elseif ($amount === null) {
            throw ValidationException::withMessages(['amount' => ['مبلغ را وارد کنید.']]);
        }

        $description = $this->describePurchase($amount, $purpose);
        $token = bin2hex(random_bytes(32));
        $expires = now()->addMinutes((int) config('payments.mobile.token_ttl_minutes'));

        $payment = $this->createPendingPayment(
            $user,
            $amount,
            self::MOBILE_PENDING_PREFIX.(string) \Illuminate\Support\Str::uuid(),
            $description,
            $purpose,
            ['mobile_token_hash' => self::hashToken($token), 'mobile_token_expires_at' => $expires],
        );

        return [
            'payment_id' => $payment->id,
            'url' => rtrim((string) config('payments.mobile.page_url'), '/').'/'.$token,
            'amount' => $amount,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    private function findByMobileToken(string $token, bool $lock = false): ?Payment
    {
        $query = Payment::where('mobile_token_hash', self::hashToken($token));

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    private function hasGatewayAuthority(Payment $payment): bool
    {
        return !str_starts_with((string) $payment->authority, self::MOBILE_PENDING_PREFIX);
    }

    /** ready | expired | paid | failed — what the payment page shows. */
    private function mobileState(Payment $payment): string
    {
        return match (true) {
            $payment->status === 'success' => 'paid',
            $payment->status === 'failed' => 'failed',
            $payment->mobile_token_expires_at->isPast() => 'expired',
            default => 'ready',
        };
    }

    /**
     * What the payment page may show for a token. Nothing about the user, the authority or
     * the token itself. Null when the token is unknown.
     */
    public function mobilePaymentSummary(string $token): ?array
    {
        $payment = $this->findByMobileToken($token);
        if (!$payment) {
            return null;
        }

        return [
            'state' => $this->mobileState($payment),
            'amount' => (int) $payment->amount,
            'title' => $payment->description,
            'order_ref' => 'GRD-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * The payment page's button: creates the Zarinpal payment for the pending record and returns
     * the gateway URL. Repeating it (double tap, reload) returns the same gateway URL instead of
     * creating a second payment. Returns null for an unknown token.
     *
     * @return array{state: string, url?: string}|null
     */
    public function continueMobilePayment(string $token): ?array
    {
        return DB::transaction(function () use ($token) {
            $payment = $this->findByMobileToken($token, lock: true);
            if (!$payment) {
                return null;
            }

            $state = $this->mobileState($payment);
            if ($state !== 'ready') {
                return ['state' => $state];
            }

            if (!$this->hasGatewayAuthority($payment)) {
                $authority = $this->zarinpalRequest(
                    $payment->user,
                    (int) $payment->amount,
                    config('payments.mobile.callback_url'),
                    (string) $payment->description,
                );
                $payment->update(['authority' => $authority]);
            }

            return ['state' => 'ready', 'url' => self::gatewayUrl($payment->authority)];
        });
    }

    /**
     * Settles a mobile payment after the gateway sent the user back. The browser's `Status` is
     * not trusted: whatever it says, success comes only from Zarinpal's verify. Repeated
     * callbacks just report the recorded result.
     *
     * @return array{payment_id: int, status: string}|null  null when no mobile payment has this authority
     */
    public function settleMobilePayment(string $authority): ?array
    {
        $payment = Payment::where('authority', $authority)->whereNotNull('mobile_token_hash')->first();
        if (!$payment) {
            return null;
        }

        if ($payment->status === 'pending') {
            try {
                $this->verifyPayment($authority);
            } catch (Exception $e) {
                // Gateway unreachable or odd answer: stay pending, the app asks again.
                report($e);
            }
            $payment->refresh();
        }

        return ['payment_id' => $payment->id, 'status' => $payment->status];
    }

    /** The authoritative state of one of the user's own payments (what the app polls). */
    public function paymentStatus(User $user, int $id): ?array
    {
        $payment = Payment::where('user_id', $user->id)->find($id);
        if (!$payment) {
            return null;
        }

        return [
            'id' => $payment->id,
            'status' => $payment->status,
            'purpose' => $payment->purpose,
            'amount' => (int) $payment->amount,
            'ref_id' => $payment->ref_id,
        ];
    }

    /**
     * Redeem a discount code for a free "paid" credit, bypassing Zarinpal.
     * Creates the same kind of success/unused Payment record the normal
     * verify() flow produces, so downstream payment checks (lastPayment)
     * pass exactly as if the user had paid.
     *
     * @throws ValidationException if the code doesn't match a configured discount code
     */
    public function redeemDiscountCode(User $user, string $code): Payment
    {
        $normalized = strtolower(trim($code));
        $validCodes = array_map('strtolower', config('payments.discount_codes', []));

        if ($normalized === '' || !in_array($normalized, $validCodes, true)) {
            throw ValidationException::withMessages([
                'code' => ['کد تخفیف نامعتبر است'],
            ]);
        }

        return Payment::create([
            'user_id' => $user->id,
            'amount' => 0,
            'authority' => 'discount-'.$normalized.'-'.(string) \Illuminate\Support\Str::uuid(),
            'status' => 'success',
            'description' => 'کد تخفیف رایگان‌کننده',
            'is_used_lab_test' => false,
            'is_used_insurance' => false,
        ]);
    }

    /**
     * Records a Cafe Bazaar in-app purchase once Bazaar's developer API confirms it.
     *
     * The app only consumes the purchase after this returns success, so the same
     * token can arrive again (retry, app killed): an already-recorded token for
     * the same user is a success, never a second credit. The developer payload
     * the app sets ("u<user id>") ties the purchase to the buyer's account.
     */
    public function verifyBazaarPurchase(User $user, string $productId, string $purchaseToken): array
    {
        $product = config("payments.bazaar.products.{$productId}");
        if (!$product) {
            return ['status' => 'failed', 'message' => 'محصول نامعتبر است'];
        }

        $authority = 'bazaar-'.$purchaseToken;
        $existing = Payment::where('authority', $authority)->first();
        if ($existing) {
            return $existing->user_id === $user->id && $existing->status === 'success'
                ? ['status' => 'success', 'message' => 'Payment already verified', 'payment_id' => $existing->id]
                : ['status' => 'failed', 'message' => 'این خرید قبلاً ثبت شده است'];
        }

        $config = config('payments.bazaar');
        $url = sprintf(
            '%s/validate/%s/inapp/%s/purchases/%s/',
            rtrim($config['api_url'], '/'),
            rawurlencode($config['package_name']),
            rawurlencode($productId),
            rawurlencode($purchaseToken)
        );

        $response = Http::withHeaders(['CAFEBAZAAR-PISHKHAN-API-SECRET' => (string) $config['api_secret']])
            ->acceptJson()
            ->timeout(15)
            ->get($url);

        // 404 = unknown token/product; anything else non-2xx is Bazaar being unreachable.
        if ($response->status() === 404 || $response->status() === 400) {
            return ['status' => 'failed', 'message' => 'خرید در بازار پیدا نشد'];
        }
        if (!$response->successful()) {
            throw new Exception('Bazaar validation request failed: '.$response->status());
        }

        $data = $response->json();
        // purchaseState: 0 = purchased, 1 = refunded.
        if (($data['purchaseState'] ?? null) !== 0) {
            return ['status' => 'failed', 'message' => 'این خرید بازگشت داده شده است'];
        }
        if (($data['developerPayload'] ?? null) !== 'u'.$user->id) {
            return ['status' => 'failed', 'message' => 'این خرید متعلق به حساب شما نیست'];
        }

        try {
            $payment = Payment::create([
                'user_id' => $user->id,
                'amount' => $product['amount'],
                'authority' => $authority,
                'status' => 'success',
                'description' => $product['description'],
                // Only the analysis product is a lab-test/insurance credit.
                'is_used_lab_test' => !$product['grants_credit'],
                'is_used_insurance' => !$product['grants_credit'],
                // The consultation product is a pre-paid consultation, spent by the next booking.
                'purpose' => ($product['grants_consultation'] ?? false) ? 'consultation' : 'analysis',
                'is_used_consultation' => !($product['grants_consultation'] ?? false),
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // A concurrent verify of the same token won the insert.
            return $this->verifyBazaarPurchase($user, $productId, $purchaseToken);
        }

        return ['status' => 'success', 'message' => 'Payment verified successfully', 'payment_id' => $payment->id];
    }

    /**
     * Find latest unused successful payment
     */
    public function lastPayment(int $userId, string $type)
    {
        return Payment::where('user_id', $userId)
            ->where('status', 'success')
            ->where($type, false)
            ->latest('created_at')
            ->first();
    }

    /**
     * Mark payment as used
     */
    public function updatePaymentUsedStatus(int $id, string $type)
    {
        $payment = Payment::find($id);

        if (!$payment) {
            throw ValidationException::withMessages([
                'payment' => ['Invalid payment id.'],
            ]);
        }

        if ($type === 'lab-test') {
            $payment->is_used_lab_test = true;
        }

        if ($type === 'insurance') {
            $payment->is_used_insurance = true;
        }

        $payment->save();

        return $payment;
    }
}
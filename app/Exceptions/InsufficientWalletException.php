<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A charge the wallet cannot cover. Answered with 403 (what clients already treat as
 * «payment needed» since the one-credit-per-request days) plus the numbers a client needs
 * to send the user to a top-up.
 */
class InsufficientWalletException extends RuntimeException
{
    public function __construct(public readonly int $required, public readonly int $balance, public readonly string $service)
    {
        parent::__construct('Payment needed to proceed');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => 'Payment needed to proceed',
            'code' => 'insufficient_wallet',
            'service' => $this->service,
            'required' => $this->required,
            'balance' => $this->balance,
            'shortfall' => max(0, $this->required - $this->balance),
        ], 403);
    }
}

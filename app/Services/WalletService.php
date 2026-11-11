<?php

namespace App\Services;

use App\Exceptions\InsufficientWalletException;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The user's wallet (IRR). The balance on `users.wallet_balance` and the rows of
 * `wallet_transactions` change together, inside one transaction holding a row lock on the
 * user, so two concurrent requests can never spend the same money twice.
 */
class WalletService
{
    public function balance(User $user): int
    {
        return (int) User::whereKey($user->id)->value('wallet_balance');
    }

    /**
     * Adds money. Idempotent per ($source, $reference): a gateway callback or a Bazaar token
     * that arrives twice credits once; the second call returns the first transaction.
     */
    public function credit(User $user, int $amount, string $source, ?string $reference, string $description, array $meta = []): WalletTransaction
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('A credit must be positive.');
        }

        try {
            return DB::transaction(function () use ($user, $amount, $source, $reference, $description, $meta) {
                if ($reference !== null) {
                    $existing = WalletTransaction::where('source', $source)->where('reference', $reference)->first();
                    if ($existing) {
                        return $existing;
                    }
                }

                $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('wallet_balance');
                $balanceAfter = $balance + $amount;
                User::whereKey($user->id)->update(['wallet_balance' => $balanceAfter]);

                return WalletTransaction::create([
                    'user_id' => $user->id,
                    'type' => 'credit',
                    'amount' => $amount,
                    'balance_after' => $balanceAfter,
                    'source' => $source,
                    'reference' => $reference,
                    'description' => $description,
                    'meta' => $meta ?: null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent request recorded the same reference first.
            return WalletTransaction::where('source', $source)->where('reference', $reference)->firstOrFail();
        }
    }

    /**
     * Spends money, or throws InsufficientWalletException without changing anything.
     */
    public function debit(User $user, int $amount, string $service, string $description, array $meta = []): WalletTransaction
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('A debit must be positive.');
        }

        return DB::transaction(function () use ($user, $amount, $service, $description, $meta) {
            $balance = (int) User::whereKey($user->id)->lockForUpdate()->value('wallet_balance');
            if ($balance < $amount) {
                throw new InsufficientWalletException($amount, $balance, $service);
            }

            $balanceAfter = $balance - $amount;
            User::whereKey($user->id)->update(['wallet_balance' => $balanceAfter]);

            return WalletTransaction::create([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'source' => $service,
                'description' => $description,
                'meta' => $meta ?: null,
            ]);
        });
    }

    /**
     * Puts back what a failed or undone service charged. Idempotent on the debit it reverses.
     */
    public function refund(WalletTransaction $debit, string $description): ?WalletTransaction
    {
        if ($debit->type !== 'debit') {
            return null;
        }

        $user = User::findOrFail($debit->user_id);

        return $this->credit($user, $debit->amount, 'refund', 'debit-'.$debit->id, $description, ['debit_id' => $debit->id]);
    }
}

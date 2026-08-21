<?php

namespace App\Challenges\AppCompletion\Services;

use App\Challenges\AppCompletion\DataTransferObjects\TransferData;
use App\Challenges\AppCompletion\DataTransferObjects\TransferResult;
use App\Challenges\AppCompletion\Models\WalletTransfer;
use App\Challenges\Shared\Exceptions\DomainException;
use App\Challenges\Shared\Models\Wallet;
use Illuminate\Support\Facades\DB;

class WalletTransferService
{
    public function transfer(TransferData $input): TransferResult
    {
        if ($input->amount <= 0) {
            throw new DomainException('INVALID_AMOUNT', 'Transfer amount must be a positive integer.');
        }

        if ($input->fromWalletId === $input->toWalletId) {
            throw new DomainException('SAME_WALLET_TRANSFER', 'Cannot transfer to the same wallet.');
        }

        return DB::transaction(function () use ($input) {
            $wallets = $this->lockWallets($input->fromWalletId, $input->toWalletId);

            $from = $wallets[$input->fromWalletId] ?? null;
            $to = $wallets[$input->toWalletId] ?? null;

            if (! $from || ! $to) {
                throw new DomainException('WALLET_NOT_FOUND', 'Wallet was not found.', 404);
            }

            if (! $from->isActive()) {
                throw new DomainException('WALLET_NOT_ACTIVE', 'Source wallet is not active.');
            }

            if ($from->balance < $input->amount) {
                throw new DomainException('INSUFFICIENT_BALANCE', 'Source wallet has insufficient balance.');
            }

            $from->decrement('balance', $input->amount);
            $to->increment('balance', $input->amount);

            $transfer = WalletTransfer::create([
                'from_wallet_id' => $from->id,
                'to_wallet_id' => $to->id,
                'amount' => $input->amount,
            ]);

            return TransferResult::fromModels($transfer, $from, $to);
        });
    }

    /**
     * Locks both wallet rows, always in ascending id order rather than request
     * order, so two transfers moving money in opposite directions between the
     * same pair of wallets queue up instead of deadlocking on each other.
     *
     * @return array<int, Wallet>
     */
    private function lockWallets(int $fromWalletId, int $toWalletId): array
    {
        $ids = [$fromWalletId, $toWalletId];
        sort($ids);

        $wallets = [];

        // Locked one id at a time: an ordered whereIn would not guarantee the
        // engine acquires the locks in that order.
        foreach ($ids as $id) {
            $wallet = Wallet::whereKey($id)->lockForUpdate()->first();

            if ($wallet) {
                $wallets[$id] = $wallet;
            }
        }

        return $wallets;
    }
}

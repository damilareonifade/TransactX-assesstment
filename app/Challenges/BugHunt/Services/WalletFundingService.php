<?php

namespace App\Challenges\BugHunt\Services;

use App\Challenges\BugHunt\DataTransferObjects\FundingData;
use App\Challenges\BugHunt\DataTransferObjects\FundingResult;
use App\Challenges\BugHunt\Jobs\SendFundingReceipt;
use App\Challenges\BugHunt\Models\WalletFunding;
use App\Challenges\Shared\Exceptions\DomainException;
use App\Challenges\Shared\Models\Wallet;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

class WalletFundingService
{
    public function fund(FundingData $input): FundingResult
    {
        if ($input->amount <= 0) {
            throw new DomainException('INVALID_AMOUNT', 'Funding amount must be positive.');
        }

        // Checked before anything is credited. Previously the wallet was topped up
        // first and the duplicate check ran afterwards, so every provider retry of
        // the same event credited the wallet again.
        if ($recorded = $this->recordedFunding($input->reference)) {
            return $recorded;
        }

        try {
            $result = DB::transaction(fn () => $this->recordAndCredit($input));
        } catch (UniqueConstraintViolationException) {
            // A concurrent request recorded this reference in the gap between the
            // check above and our insert. The unique index rejected ours, so return
            // the winner's record rather than surfacing a raw database error.
            return $this->recordedFunding($input->reference)
                ?? throw new DomainException('FUNDING_CONFLICT', 'Funding could not be recorded.', 409);
        }

        // Dispatched only once the transaction has committed, so a funding that
        // rolled back never sends a receipt.
        SendFundingReceipt::dispatch($result->walletId, $result->fundingId);

        return $result;
    }

    private function recordAndCredit(FundingData $input): FundingResult
    {
        // Locked so the credit and the balance reported back stay consistent when
        // two fundings for different references hit the same wallet at once.
        $wallet = Wallet::whereKey($input->walletId)->lockForUpdate()->first();

        if (! $wallet) {
            throw new DomainException('WALLET_NOT_FOUND', 'Wallet was not found.', 404);
        }

        // Recorded before the credit so the unique index rejects a duplicate
        // reference before any money moves. Both statements share one transaction,
        // so a failure to persist the record rolls the balance back with it.
        $funding = WalletFunding::create([
            'wallet_id' => $wallet->id,
            'amount' => $input->amount,
            'reference' => $input->reference,
        ]);

        $wallet->increment('balance', $input->amount);

        return FundingResult::fromModels($funding, $wallet);
    }

    /**
     * The reference is a globally unique provider event id, so the lookup is not
     * scoped to a wallet, and the result is built from the wallet the original
     * funding actually credited rather than the one this request asked for.
     */
    private function recordedFunding(string $reference): ?FundingResult
    {
        $funding = WalletFunding::with('wallet')->where('reference', $reference)->first();

        return $funding ? FundingResult::fromModels($funding, $funding->wallet) : null;
    }
}

# Submission: App Completion

Fill this in and include it with your solution.

## Time & tools

- Start time: <!-- fill in -->
- End time: <!-- fill in -->
- Tools/resources you used (docs, Stack Overflow, an AI assistant, etc., name them
  plainly; this is informational, not a trick question):
  Laravel docs (`lockForUpdate`, `DB::transaction`), and Claude Code as an AI assistant.
- If you used an AI tool, roughly how did you use it (e.g. "asked it to explain
  `lockForUpdate`", "generated a first draft and rewrote most of it", "wrote it myself
  and asked it to review"):
  I used Claude Code to read the kit and draft the implementation, then went through it
  line by line and made the calls I disagreed with myself. The lock ordering and the
  decision to lock one id at a time are the parts I spent most of the time on.

## Debrief

Answer these about the code you're submitting, not in the abstract.

1. Without running it, what does `test_rejects_transfer_from_a_suspended_wallet`
   expect your code to do, and which lines make that pass?

   It creates a suspended source wallet with a balance of 10,000 and sends 1,000, so the
   balance is deliberately more than enough. The only thing wrong with the transfer is
   the source wallet's status, and it expects a `DomainException` carrying
   `WALLET_NOT_ACTIVE`.

   The `if (! $from->isActive())` check in `transfer()` is what throws. It sits after
   the wallets are locked and loaded, and before the balance check, so a suspended
   wallet is rejected on status rather than falling through to some other error. Note it
   only looks at the source: a suspended wallet can still be paid into, which is why
   there's no equivalent check on `$to`.

2. Why did you lock wallet rows in the order you chose? What would you observe in
   production if you'd locked them in request order (`from_wallet_id` then
   `to_wallet_id`) instead?

   I sort the two ids and lock in ascending order, so every transfer touching the same
   pair of wallets always takes the lower id first regardless of which direction the
   money is going.

   In request order, wallet 7 paying wallet 12 would hold 7 and wait for 12, while 12
   paying 7 at the same moment holds 12 and waits for 7. Neither can proceed. In
   production that shows up as intermittent deadlock errors under load (MySQL 1213),
   transfers that fail and then succeed on retry, clustered on pairs of wallets that pay
   each other often. It is close to impossible to reproduce locally, which is what makes
   it worth getting right up front.

   One detail worth flagging: I lock the two rows in two separate queries rather than
   one `whereIn(...)->orderBy('id')->lockForUpdate()`. The single query looks equivalent
   but isn't. The engine locks rows as it reads them, and with a sequential scan the
   read order is physical row order, not the `ORDER BY`. Two explicit queries make the
   ordering actually true instead of incidental, and the extra round trip is cheap.

3. Suppose `WalletTransfer::create()` threw an exception right after you'd already
   debited the source wallet, but `DB::transaction()` wasn't there to wrap the whole
   operation. What state would the database be left in? None of the automated tests
   force this failure directly. Why not, and how would you test for it if you had
   more time?

   Both balance writes would already have run, so the source is debited, the destination
   is credited, and there is no `wallet_transfers` row explaining either. That's worse
   than money going missing: the money has actually moved, and nothing in the ledger says
   why. Reconciliation would show two wallets whose balances don't match their history,
   with no way to tell whether it was one transfer or ten.

   The tests don't force it because they drive the service through its public API with
   real models and a real database. Nothing is stubbed, so there's no seam where
   `create()` can be made to fail. The suite can check the happy path and every
   validation branch, but not a failure partway through a write it can't cause.

   I'd test it the same way the Bug Hunt suite does: register a `WalletTransfer::creating`
   listener that throws for a specific amount, call `transfer()`, and assert both wallet
   balances are unchanged and `wallet_transfers` is empty. The hook fires inside the
   transaction at exactly the point of interest, so it's deterministic and needs no
   mocking of the database layer. Same reasoning applies to a test that asserts the
   debit and credit always sum to zero across a batch of transfers.

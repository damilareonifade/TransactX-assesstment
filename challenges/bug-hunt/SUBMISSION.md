# Submission: Bug Hunt

Fill this in and include it with your solution.

## Time & tools

- Start time: <!-- fill in -->
- End time: <!-- fill in -->
- Tools/resources you used (docs, Stack Overflow, an AI assistant, etc., name them
  plainly; this is informational, not a trick question):
  Laravel docs (transactions, `UniqueConstraintViolationException`), and Claude Code as
  an AI assistant.
- If you used an AI tool, roughly how did you use it (e.g. "asked it to explain a
  stack trace", "generated a first draft and rewrote most of it", "wrote it myself and
  asked it to review"):
  I used Claude Code to read the service against the test suite and draft the fix, then
  reviewed each change myself. Deciding to add the unique index as a new migration
  instead of editing the shipped one, and putting the constraint-violation catch outside
  the transaction, were the two calls I thought hardest about.

## Debrief

Answer these about the code you're submitting, not in the abstract.

1. Walk through what happens, step by step, when `fund()` is called twice in immediate
   succession with the same `reference`, using your fixed code.

   First call: the amount check passes, the reference lookup finds nothing, so we open a
   transaction, lock the wallet row, insert the `wallet_fundings` record, increment the
   balance, and commit. The receipt job is dispatched after the commit returns.

   Second call: the amount check passes, the reference lookup now finds the record, and
   we return a result built from it straight away. No transaction, no credit, no second
   record, no second receipt.

   The original code did this in the opposite order. It credited the wallet first and
   checked for a duplicate second, so every retry added the money again and then returned
   a response that looked completely correct. That's the bug that was reaching users.

2. The unique constraint on `reference` is a safety net for true concurrent requests,
   not just sequential retries. Given your implementation, is there a scenario where
   two near-simultaneous requests would still cause one of them to receive a raw 500
   error instead of a graceful "already processed" response? If so, what would you
   change to close that gap?

   Not from the unique constraint itself. If both requests get past the lookup because
   neither has committed yet, the loser blocks on the insert until the winner commits,
   then takes the violation, which I catch and turn into the winner's record. The catch
   is deliberately outside `DB::transaction()`: on Postgres a constraint violation aborts
   the transaction, so the re-read has to happen after the rollback, not inside it.

   The gap that's left is elsewhere. The wallet row lock can time out or deadlock under
   enough concurrent funding of the same wallet, and that surfaces as a `QueryException`
   the code doesn't catch, so it becomes a 500. The provider would retry it and probably
   succeed, but a 500 is the wrong signal to send. I'd catch lock-wait timeouts and
   deadlocks and retry the transaction a couple of times before giving up.

   The other thing I'd add, which no test covers: right now a replay carrying the same
   reference but a different amount silently returns the original result. That's the
   correct response, but it means the provider and we disagree about the event, and
   nobody finds out. I'd log that mismatch loudly rather than swallow it.

3. Without running it, what does
   `test_rolls_back_the_wallet_balance_if_the_funding_record_fails_to_persist` actually
   simulate, and why does it use a model event hook instead of a real network or
   database failure?

   It simulates the funding row failing to persist partway through the operation, which
   in production would be a dropped connection, a disk error, or a constraint firing at
   the worst moment. What it's really asserting is the invariant: if the record doesn't
   land, the balance must not move either.

   It uses a `WalletFunding::creating` hook because that's the only deterministic seam
   available. You can't reliably make a real database or network fail on demand inside a
   test, and the suite runs on in-memory SQLite, which has no connection to sever. The
   hook throws inside the same transaction at precisely the point the test cares about,
   so it's fast, repeatable, and needs no mocking of the database layer. It tests the
   rollback, not any particular cause of failure, which is the right thing to pin down.

   One honest note about my implementation: I insert the funding record before
   incrementing the balance, so in this test the balance is never touched in the first
   place. The assertion still holds, and the transaction would still hold it if the two
   statements were the other way round, but the insert-first order means the unique index
   rejects a duplicate before any money moves at all.

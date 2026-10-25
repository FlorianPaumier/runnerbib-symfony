# Whole-checkout cancellation checkpoint — 2026-10-11

Protected seller withdrawal uses the existing `ListingStore::withdraw(sellerId, listingId, expectedVersion, now)` contract. It discovers every race in the protected checkout before taking any race lock, locks those races in sorted order, and rechecks the protected checkout identity. A changed identity fails without mutation. The owner/version/lifecycle and checkout state are revalidated under locks. The requested listing becomes inactive, its availability is invalidated and the withdrawal is audited; every checkout item and buyer/race guard stays protected.

The same transaction records `cancellation_requested_at` for the whole checkout and one `checkout.cancellation_requested` outbox event containing only the original operation ID. Different item withdrawals from one checkout preserve its original request. No financial operation, refund result or purchase is invented by requesting cancellation.

The versioned `CheckoutCancellationMessageHandler` requires valid checkout/operation UUID context and payload version. The scope-bound checkout journal must match that original operation and already contain a durable cancellation request before a worker can claim it. Redelivery after a safely cancelled original checkout reads its terminal result without another generation or provider call.

Reconciliation honors a cancellation request before the initial 15-minute deadline. A journal with no original attempt can safely cancel locally under the checkout lease, and late payment-creation jobs are refused after the cancellation request. A known open original Session is explicitly expired and re-inspected outside PostgreSQL transactions. Confirmed safe closure releases every checkout item and guard atomically. Processing, unknown and paid paths retain all protection and the durable cancellation/recovery requirement; an inactive listing stays inactive.

Observed red → green:

- Protected owner withdrawal incorrectly returned false → inactive listing plus durable whole-checkout cancellation request.
- Cancellation before the initial deadline left the original Session open → expire and authoritative re-inspection.
- A late payment queue delivery created after seller cancellation → refuse before any provider mutation.
- Missing cancellation handler → strict original-context reconciliation; redelivery initially rejected cancelled checkout → read-only terminal acknowledgement.

Actual PostgreSQL regression evidence includes two independently running withdrawals of items from different races in the same checkout. Both contended on the held shared race lock, completed without deadlock, produced one cancellation request, retained both protected items, and later released both items and guards together after safe no-attempt reconciliation. This focused concurrency test passed with 15 assertions. Existing affected Selling lifecycle/access tests passed: 18 tests, 137 assertions. Focused processing/paid/unknown and invalid-message tests passed: 4 tests, 49 assertions. Scoped PHPStan level 10, container lint and Deptrac pass; formatting is applied. Combined Checkout/Finance/affected Selling coverage run: 148 tests, 832 assertions, green. Scope line coverage: ReconcileCheckout 15/15, cancellation handler 10/10, receipt use case 2/2, inbox adapter 6/6 and signature authenticator 38/38. Reconciliation persistence 32/34 and submission persistence 36/38 retain two schema/native-value guards each; listing persistence 103/112 also has completion-conflict and changing-checkout-context scenarios awaiting further evidence. The scope run excludes other Domain unit tests, so it does not represent global coverage.

```sh
docker compose --env-file .env.docker exec -T -e APP_ENV=test -e XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Integration/Checkout tests/Integration/Finance tests/Selling/ListingLifecycleTest.php tests/Selling/ListingAccessTest.php --coverage-clover var/checkout-payment-coverage.xml
```

CHECK-04 remains open for collected-funds recovery and the full serialized completion conflict once authoritative purchase finalization exists. A state-only paid classification cannot establish a refund or replace verified original PaymentIntent/charge/provider-account and exact amount-received evidence. No actual Stripe call or money transfer was made, no new table was added, and accepted operator/legal configuration remains a launch gate.

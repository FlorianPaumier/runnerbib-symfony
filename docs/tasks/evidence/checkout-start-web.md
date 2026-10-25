# Buyer checkout initiation — 2026-10-19

Incremental CHECK-01/CHECK-02 work from the Step 8 backlog: a buyer starts a
hosted payment from the cart and lands on the authoritative state of that
checkout. Purchase finalization, collected-funds recovery, receipts and real
Stripe acceptance remain open.

## Implementation

`StartCheckout` (Application) composes the existing `AuthorizeCheckout` and
`SubmitHostedCheckout`. It maps the races the buyer acknowledged to the
accepted acknowledgment version owned by `AuthorizeCheckout`, authorizes the
whole cart, and submits the original payment operation to the hosted provider.
`CheckoutStart` carries the checkout identity and the provider session URL; a
provider failure leaves the checkout protected and is reconciled later instead
of being reported as a payment.

The cart view now exposes the race participation and the race-required
registration fields (name, type, required, options) taken from the stored
registration schema, so the cart page renders the payment form. Answers are
converted to the declared types in the Presentation layer and re-validated by
the existing Domain registration rules, which stay authoritative.

`POST /panier/payer` requires the CSRF token, refuses a cart that cannot be
authorized with a French `409`, and answers `503` while the payment provider
is unavailable. No protected checkout is created by a refused request. The
committed provider configuration points at `/achat/retour?session={CHECKOUT_SESSION_ID}`;
Stripe substitutes the session identifier, and the return route resolves the
checkout that belongs to the signed-in buyer only.

The secure-callback rule moved from the `AuthorizeCheckout` constructor to the
point of authorization: a deployment without a public `https` base URL now
answers with a French error instead of breaking the container.

## Observed red → green

- `StartCheckoutTest` red: `Class "App\Application\Checkout\StartCheckout" not
  found` (3 errors) → implemented the use case, the result object and the
  acknowledgment accessor → green.
- The first green attempt failed with `Seller cannot receive transfers`: the
  controlled seller provider defaults to pending, so the test now marks the
  seller ready.
- Cart page red: `Unknown "selectattr" filter in "cart/index.html.twig"` → the
  payable rule moved into the cart view (`CartView::payable`).
- HTTP test red: `The "App\Application\Checkout\AuthorizeCheckout" service is
  already initialized, you cannot replace it`. Replacing wired services does
  not reach the request path: Symfony's test container only replaces services
  the application container does not hold, so the test now sets the provider
  scope and the public base URL through the environment and keeps the
  configured adapters.
- HTTP test red: `Seller provider operator configuration is required` (500) →
  the controller answers `503` and no checkout is protected.
- HTTP test red: `NotNullConstraintViolationException` on
  `payment_attempts.operation_id` → the fixture inserts the financial
  operation.
- `CheckoutAuthorizationTest` red after the callback check moved:
  `Failed asserting that exception of type "DomainException" is thrown` → the
  insecure callback case now proves the refusal at authorization time.
- The first coverage run of this slice failed the overall gate at 97.71 %.
  The provider-dependent redirect was reduced to one statement, the redundant
  stored-schema guards were folded into the existing row guard, and the
  anonymous, empty-cart, unknown-listing and registration-answer paths were
  covered.

## Verification commands

Run from `symfony-app/`:

```sh
make test
make coverage
make lint analyse cs-check audit
```

Observed results:

- `make test` (ParaTest, one process per CPU): 799 tests, 5,037 assertions, exit 0.
- `make coverage`: 98.05 % overall (2,864/2,921 lines) and 100.00 %
  Domain/Application (989/989); both gates pass.
- `make lint analyse cs-check audit`: YAML/container/Deptrac, PHPStan level 10,
  PHP CS Fixer and Composer audit pass.
- Focused mutation on the new Application and Domain files
  (`StartCheckout`, `CheckoutStart`, `ManageCart`, `FindCheckoutStatus`,
  `RegistrationSchema`, `RegistrationField`): 250 mutants, 239 killed, 100 %
  mutation code coverage, 95 % covered MSI.

  The first run escaped 11 mutants. The new slice contributed five of them on
  the random payment worker label, one on the catch clause of the use case and
  one on the anonymous lookup; the worker label is now the stable
  `checkout-web`, the refused submission and the anonymous/empty-session
  lookups are covered, and the anonymous lookup and the unpayable cart are
  covered by new tests.

  The remaining survivors are pre-existing gaps in the registration bounds and
  enumeration validation (`minLength` of zero, equal minimum and maximum,
  enumeration type guards, whitespace-only field names) and one equivalent
  mutant in `ManageCart` where the unreachable branch throws the same
  exception. They belong to the global mutation acceptance, which stays open.

## Limitations

- No real Stripe call. The environment has no provider credentials, so the
  hosted session URL is proven at the Application boundary with a controlled
  provider; the HTTP redirect to the provider is not exercised.
- The seller payout readiness is reconciled with the provider before a
  payment, so an environment without provider credentials cannot complete a
  payment. The request is refused with a French `503` and the cart stays
  intact.
- The browser return is not proof of payment: the state page shows the
  backend state only. Purchase finalization and collected-funds recovery stay
  unfinished.
- Nothing was committed or deployed.

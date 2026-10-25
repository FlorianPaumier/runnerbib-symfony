# Accounts, authentication and organizations

Status: planned, 2026-10-08. Authorization belongs to application use cases as well as HTTP adapters. Public discovery remains independently deliverable.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## AUTH-01 — Account and private profile lifecycle

- [ ] Complete this task and link executed evidence.

Dependencies: BASE-01.

Deliver: Implement Account values, terms acceptance, separate private profile and audited lifecycle persistence.

Acceptance: Email policy is explicit; signup does not require purchase registration fields; private profiles are excluded from public DTOs.

## AUTH-02 — Email/password signup and verification

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-01, BASE-05, BASE-02.

Deliver: Implement signup/login/logout, password hashing, single-use verification credentials and French account screens.

Acceptance: Invalid credentials, duplicate signup and consumed/expired tokens fail safely; verified state is authoritative; messages do not leak account existence.

## AUTH-03 — Google sign-in and identity linking

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-01, DEC-09.

Deliver: Implement verified provider identity handling behind an adapter and safe account-linking rules.

Acceptance: Invalid callback/state/provider subject is rejected; repeated login creates no duplicate account; email alone cannot authorize unsafe identity merging.

## AUTH-04 — Password recovery and session security

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-02, DEC-09.

Deliver: Implement single-use reset flows, CSRF, session expiry/invalidation and rate limiting.

Acceptance: Reset/logout/revocation removes access as required; replay/expired tokens and cross-account credential changes are rejected.

## AUTH-05 — Role and resource authorization

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-01.

Deliver: Implement actor context and policies for runners, admins, super-admins and organization membership.

Acceptance: Cross-account/race/organization identifier access is denied; ordinary admins cannot approve policy refunds, initiate recovery or lift seller suspensions.

## ORG-01 — Organization approval and membership

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-05.

Deliver: Implement approval workflow, owner/manager roles, membership history and atomic owner transfer.

Acceptance: One active organization/account and at most one active owner are enforced; manager cannot manage membership; operative approval has an owner.

## ORG-02 — Organization resource scope

- [ ] Complete this task and link executed evidence.

Dependencies: ORG-01.

Deliver: Apply organization authorization to race/import/export reads and mutations; provide owner/manager French dashboard/navigation.

Acceptance: Tampered identifiers cannot expose another organizer records, files or CSV; resource checks work without depending on UI visibility.

## AUTH-06 — Account and public profile screens

- [ ] Complete this task and link executed evidence.

Dependencies: AUTH-02, AUTH-03, AUTH-04, AUTH-05, BASE-02.

Deliver: Build private profile/preferences and minimal public name/bib views.

Acceptance: Purchase fields are collected at purchase; public profile never exposes contact data, original-holder identity or proof references.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).

# Decisions needed before affected features ship

Status: planned, 2026-10-08. Record accepted answers in the authoritative decision register. These tasks gate their affected feature, not all implementation.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## DEC-01 — Resale cutoff policy

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Decide handling for races with no explicit resale deadline; preserve known existing values.

Acceptance: Publication/checkout behavior and French copy specify the missing-configuration outcome without a guessed unlimited period.

## DEC-02 — Race registration and import contract

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Agree field keys/types, required fields, bib/email normalization, CSV encoding, size/row limits and update allowlist.

Acceptance: Versioned field schema and literal valid/invalid examples exist; ownership and payment fields cannot be updated through imports.

## DEC-03 — Private proof handling

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Agree allowed document types, upload size limits, quarantine/scanning, authenticity checklist, redaction and race-plus-dispute retention.

Acceptance: Reviewers have an actionable procedure and storage/purge rules; proof approval never certifies the transfer protocol.

## DEC-04 — Financial and newsletter retention

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Set justified category durations and open-case retention exceptions, including exports, audit/provider payloads and backup restoration.

Acceptance: Every retained personal category has purpose, duration, access and a reviewable hold policy; no indefinite blanket hold.

## DEC-05 — Refund ownership and recovery

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Decide ownership/restoration/resale consequences of refunds and permitted provider recovery mechanisms.

Acceptance: Financial refund does not implicitly restore ownership; failed recovery remains a receivable with no automatic bank debit or unapproved offset.

## DEC-06 — Official registration and receipt content

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Agree CSV fields, supported organizer integration/evidence and approved French receipt/confirmation wording.

Acceptance: Platform purchase and evidenced official registration are distinct; organizer approval never gates completed purchase.

## DEC-07 — Public content and testimony moderation

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Agree testimony submission/review/publication rules, contact/help content and approved legal/privacy/CGV wording.

Acceptance: Launch moderation rules and content are approved; unresolved legal intent is not presented as a compliance determination.

## DEC-08 — Operational budgets and recovery policy

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Set reconciliation intervals, stuck-operation escalation thresholds, query latency/data-volume targets, upload limits and accessibility acceptance target.

Acceptance: Observable acceptance criteria and operational escalation owners are recorded; no invented SLA.

## DEC-09 — Deployment and client configuration

- [ ] Complete this task and link executed evidence.

Dependencies: None.

Deliver: Confirm Cloud region/resource sizing, Symfony 8.1 runtime support, asset toolchain, web session strategy, credential mapping and concrete queue/storage adapters.

Acceptance: Project stays on requested Symfony 8.1; accepted PostgreSQL/Redis/Laravel Cloud/Twig/UX/Tailwind choices are preserved and conflicting version references reconciled.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).

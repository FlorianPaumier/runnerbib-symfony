# After web launch

Status: planned, 2026-10-08. These tasks are outside launch completion and must not delay the accepted French web release.

Use the [task index and completion rules](README.md). Dependencies reference task IDs, not automatic authorization to execute another task.

## LATER-01 — React Native and custom mobile endpoints

- [ ] Complete this task and link executed evidence.

Dependencies: REL-08.

Deliver: Agree mobile platform/authentication/distribution choices; expose required custom Symfony JSON adapters to existing Application use cases and implement native journeys.

Acceptance: Shared purchase/finance authority is reused; secure credentials, reconnect/payment return and native acceptance tests pass; no API Platform introduced by default.

## LATER-02 — Chat, English and optional analytics

- [ ] Complete this task and link executed evidence.

Dependencies: REL-08.

Deliver: Prioritize explicitly before adding deferred chat, English localization or optional tracking/search infrastructure.

Acceptance: New scope has its own requirements, consent/access/retention design and acceptance evidence; no launch task silently depends on these features.

Sources: [product rules](../../../specifications/product-rules.md), [backend requirements](../../../specifications/backend/specification.md), [web requirements](../../../specifications/frontend/specification.md), [architecture plans](../architecture/README.md), [open decisions](../../../specifications/decisions-and-gaps.md).

# Legacy application boundary

Recorded 2026-10-12 while applying `todo.md`.

The user was asked which of `api-bib`, `web-app` and `bib-waitlist` remain deployed or supported and answered **None**. Their security remediation is therefore outside the current Symfony rebuild scope. Retain the existing source as reference; do not spend implementation effort rebuilding or patching retired workflows.

No legacy source, data, deployment or provider registration was deleted or modified. Local Docker inventory showed no running legacy Runnerbib application. Containers belonging to another football project were left untouched; the Symfony rebuild stack was started on its configured loopback port8082 with its existing volumes preserved.

This establishes the application support/deployment boundary, not the separate REL-07 data transition decision. Importing retained historical data or starting fresh still needs an explicit choice before release. No production database reset or remote resource retirement is inferred from this answer.

## Data transition decision — 2026-10-12

The user explicitly selected **Start with fresh data**. REL-07 requires no legacy import, mapping or migration dry-run. The launch will use the new Symfony schema and new authorized records. All legacy applications and retained data remain preserved; this choice does not authorize resetting an existing database, removing volumes or retiring remote resources.

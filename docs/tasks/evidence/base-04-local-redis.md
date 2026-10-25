# Local Redis checkpoint — 2026-10-10

Development and production Compose include password-protected Redis7.4 on the private network. `REDIS_URL` configures the application cache and Redis session handler; environment-specific prefixes separate application/test sessions. Production cookies use Secure, HttpOnly and SameSite=Lax. Test HTTP sessions use Symfony mock-file storage.

Actual runtime smoke through Predis verified session open/write/read/destroy and configured `cache.app` save/read/delete using unique fixture keys only. An initial smoke omitted the required handler open call; the corrected lifecycle passed. No shared cache flush occurred. Redis is disposable: PostgreSQL holds ownership, availability, checkout, finance and messaging intent.

Both Compose configurations parsed. Development Docker build passed. Production build also passed; an isolated production stack migrated through Version20261009000200 and returned200 for home, search, login, health and readiness. Its services/network were removed with volumes preserved. This verifies that build snapshot; subsequent source changes and global release gates remain pending. CI Redis variables now apply across the whole verification job rather than only startup. Cloud-managed adapter/configuration evidence remains unavailable, so BASE04 is not complete.

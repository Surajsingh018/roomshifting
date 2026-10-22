# Production readiness work queue

This list tracks unfinished work from the PRD audit. It is deliberately separate from local Gate A–C acceptance and does not claim production readiness.

## Current session evidence — 2026-10-21

| Work item | Status | Evidence / remaining work |
| --- | --- | --- |
| Migration reconciliation analysis | VERIFIED | Read migrations 000–006, runner search (none), ledger, live schema, FK/index metadata, and orphan counts. `docs/MIGRATION_RECONCILIATION_PLAN.md` contains exact proposed DDL and recovery steps. No live DDL ran. |
| Isolated restore | VERIFIED | Current dump restored to `roomshift_restore_test_20261021_audit01`; before tests it contained 25 tables and matched original services/enquiries/quotes/bookings counts (3/4/4/5). Current restore now includes synthetic security-test fixtures and transactions; it remains unmigrated. Live database was not used by tests. |
| PHP security/workflow suite | IMPLEMENTED_UNVERIFIED | `python tests/php/security_workflow_test.py`: 30/30 passed against isolated restore; `php tests/php/booking_actor_check.php` passed; pricing/config/static suites passed. Remaining full route RBAC and input matrices are listed below. |
| PHP syntax | VERIFIED | Recursive `php -l` passed for all 58 PHP files; config bootstrap loaded successfully. |
| Flutter API capability alignment | IMPLEMENTED_UNVERIFIED | Flutter now sends enquiry/quote capabilities, avoids invented confirmed/current-time booking values, and requires an explicit release API URL; analyze clean, tests 3/3, debug APK built. Live UI/API flow remains pending. |
| OpenAPI artifact synchronization | VERIFIED | `openapi.yaml` copied to `docs/api/openapi.yaml`; matching SHA-256 `FA964DB2ECEFCB7BF87D595841E63DD54F8F486059CD7970F7A8EA641CC43C17`. Route-level behavior parity remains unfinished. |

## P0 — verify and harden before any shared deployment

- [ ] Reconcile all route/method/status/schema definitions against both OpenAPI copies; add behavior-based API contract tests. (Both copies now match byte-for-byte; full behavioral parity is still pending.)
- [ ] Run authentication/RBAC matrix: unauthenticated, staff, admin, revoked token, inactive user, bad credentials, rate limit, CSRF invalid/missing, session fixation.
- [ ] Audit every admin mutation, CMS field, upload route and API input for strict allow-lists, ownership checks, length/range checks, prepared SQL, and safe output encoding.
- [ ] Fail closed on production configuration: TLS-only cookies, no debug, strong unique session name, DB and rate-limit secrets, provider secrets only when enabled. Keep local XAMPP defaults limited to local mode.
- [ ] Verify `.env` denial at both nested and public route paths; verify uploaded content cannot execute and is served with safe headers.
- [ ] Add automated tests for quote boundaries/minimum/GST, idempotency collisions/concurrency, every booking state transition, status-history actor, and webhook signature/replay. (Formula, same-key/conflict, concurrent booking uniqueness, one legal/one illegal transition, and actor attribution now pass on the isolated restore; remaining transition graph and webhook replay/signature tests are open.)
- [x] Inspect all migration files, the migration ledger and live schema; document the version mismatch and exact proposed FK SQL without running it.
- [x] Verify backup hashes and restore current DB dump into an isolated test database (25 tables; counts matched).
- [ ] Run the proposed reconciliation DDL on the isolated clone only after explicit approval; repeat orphan/schema checks and capture complete post-migration behavior. Never reset the existing database.

## P1 — close PRD operations and product gaps

- [ ] Obtain owner decisions for launch areas/catalog/prices/languages, cancellation/refund/retention/privacy, payment/SMS/email/maps/push providers, partner verification, dispatch capacity, status rules and production hosting.
- [ ] Implement partner onboarding/verification and partner dispatch only after policy is approved; current partner table is empty.
- [ ] Integrate a real payment provider in sandbox, including signed events, replay handling, refund state and reconciliation; current online payment is a pending placeholder.
- [ ] Implement notification templates, worker/scheduler, retries/dead letters, observability and provider delivery; queued rows do not mean messages were sent.
- [ ] Complete OTP verification and proof capture/storage/access/retention with authorization and audit history.
- [ ] Seed CMS only with owner-approved content; current service_areas/pages/faqs/banners/offers have no rows.
- [ ] Decide customer account and cross-device booking policy before adding authenticated customer booking history to Flutter/web.
- [ ] Test full admin CRUD, filters, pagination, dashboard totals, accessibility, responsive breakpoints, browser console and SEO/canonical/OG/sitemap behavior.

## P2 — staging and go-live

- [ ] Set up production-like staging with TLS, isolated DB, least-privilege DB account, secret manager/environment config, backups and restore test.
- [ ] Run PHP/Flutter lint, automated unit/integration/security suites, web/mobile smoke tests, load/performance and dependency review on the release candidate.
- [ ] Verify all API routes return the shared envelope including 404/405/validation/auth/internal errors; publish validated OpenAPI artifact.
- [ ] Configure monitoring, logs without secrets/PII, alerting, scheduled jobs, incident/rollback runbook, and retention policy.
- [ ] Complete human acceptance for gates D/E against approved policies and production-like deployment; record timestamped evidence without exposing customer data.

## Current known external blockers

- PRD product/operations decisions have not been provided.
- No payment, messaging, maps or push provider credentials/configuration are available; do not claim delivery/payment processing is active.
- Only local XAMPP is available; there is no authorized staging/production host or domain in this workspace.
- No Git metadata is present, so original tracked/untracked status and patch diff cannot be established. The current database migration history is only partly attributable: markers 003?006 are present; prior history 000?002 cannot be proven.

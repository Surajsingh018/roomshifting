# PRD audit baseline — 2026-10-21

## Scope and source

This baseline records the RoomShift workspace before changes for the pasted “Complete PRD Implementation and Production-Readiness Master Task.” The six-page development PRD was read from `C:\Users\ankit\Downloads\Development_PRD.pdf`. It is not present at `docs/Development_PRD.pdf`; keep the supplied copy as the authoritative source. The PRD includes unresolved business decisions (launch areas/catalog/prices/languages, payment and messaging providers, cancellation/refund/privacy rules, hosting/secrets, and partner/dispatch rules); these must be confirmed before production behavior is finalized.

## Workspace and safety

- Application root: `C:\xampp\htdocs\roomshift\roomshift`; public base: `http://localhost/roomshift/`.
- No `.git` directory is present. There is no Git status, diff, or commit history to inspect; existing files and DB rows are being preserved in place.
- The adjacent Flutter project is `C:\xampp\htdocs\roomshift\flutter_app` (outside this writable workspace). Existing Flutter checks are recorded below; edits there require a separately approved filesystem escalation.
- Existing local `.env` is ignored. Its contents were not read or copied into reports.
- No database migration, reset, or destructive DB action was run for this baseline or follow-up checks.

## Baseline verification

| Check | Result | Evidence |
| --- | --- | --- |
| PHP syntax | PASS | `C:\xampp\php\php.exe -l` on all 51 PHP files returned no syntax errors. |
| PHP static API checks | PASS, limited | `php tests/php/static_api_test.php`: 8 assertions. This is a small static test, not an end-to-end suite. |
| Live catalog API | PASS | `GET http://localhost/roomshift/api/services` returned HTTP 200 and the common JSON envelope with 3 active DB-backed services. |
| Local database snapshot | PASS, read-only | 25 tables. Key counts: services 3, enquiries 4, quotes 4, bookings 5; CMS counts: service_areas 0, pages 0, faqs 0, banners 0, offers 0; operational counts: booking_status_history 7, partners 0, payments 0, notifications 7, reviews 0. No customer rows or details are copied here. Customer data is intentionally not included. |
| OpenAPI copies | PASS | Root `openapi.yaml` and `docs/api/openapi.yaml` are identical at baseline; docs identifies root as canonical. Route-by-route behavioral parity is still to be audited. |
| Flutter prior acceptance | PASS, as previously recorded | `flutter analyze`, two Flutter model tests, `flutter build apk --debug`; emulator flow loaded 3 services and created enquiry 4, quote 4, booking 5, then displayed tracking history. This was verified in the preceding Flutter task, not rerun during the initial read-only baseline. |
| Documentation presence | GAP | `docs/Development_PRD.pdf` and `docs/PRD_TRACEABILITY.md` were absent. Root README's historical Gate C table reports earlier counts (enquiries 2, quotes 2, bookings 3), which are stale relative to the current snapshot above. |

### Read-only table inventory

`admins`, `api_login_attempts`, `api_rate_limits`, `api_tokens`, `banners`, `booking_status_history`, `bookings`, `enquiries`, `faqs`, `login_attempts`, `notifications`, `offers`, `pages`, `partners`, `payments`, `pricing_rules`, `quotes`, `reviews`, `schema_migrations`, `service_areas`, `services`, `staff`, `staff_assignments`, `tracking_events`, `users`.

## Initial implementation observations

- Core PHP uses one shared PDO factory in `config/db.php`, loaded from `config/env.php`; admin and backend compatibility includes call that shared factory.
- API routes use the common `{success,data,message,error_code}` envelope and prepared statements in the inspected repositories. Catalog reads use the CMS repository; quote totals are calculated from persisted pricing rules.
- The booking repository implements transactional state changes, status history, idempotency, and tracking events. Further audit is needed for every route and permission boundary.
- Migrations 000–006 are present. Migration instructions document pre-migration SQL backup artifacts for 003–006. Do not treat those artifacts as verified restorable until an actual restore test is documented.
- Payments are COD/online-placeholder records; online provider processing is disabled. Notifications are queued; actual delivery providers/workers are not configured. Partner count is zero and partner onboarding/dispatch workflow needs verification.
- The Flutter app presents device-local saved bookings because there is no customer-authenticated booking-list API. It does not have customer identity/account policy in the current PRD implementation.
- Initial PHP baseline contained 51 source files (not the earlier Gate C count of 43); after two test additions the current count is 53.

## Known constraints

This is a local development acceptance snapshot, not evidence of production readiness. Production deployment, secret provisioning, TLS/cookie policy, provider integration and verification, backup restoration, confirmed business rules, and staging smoke tests remain separate release gates.

## Follow-up checks and safe changes

- Added `config/bootstrap.php` production-mode fail-closed checks for recognized environment names, HTTPS base URL, secure session cookies, debug disabled, a unique 32+ character rate-limit key, and a configured database password. Production login also requires a 12+ character password; the local demo login remains usable.
- Added `tests/php/pricing_engine_test.php` (7 formula assertions PASS) and `tests/php/runtime_config_test.php` (unknown/unsafe production config rejected; complete secure config accepted; production password policy tested).
- Re-ran PHP syntax across all 53 PHP files: PASS. `tests/php/static_api_test.php`: 8 assertions PASS.
- Read-only HTTP smoke checks: catalog/empty CMS lists returned 200 JSON envelopes; missing quote returned 404 envelope; missing tracking code returned 422 envelope; protected payment/notification lists returned 401 envelopes; root and admin login pages returned 200 HTML; sitemap returned parseable XML; nested `.env` returned 403.
- Re-ran Flutter checks in the existing sibling app: `flutter analyze` PASS; `flutter test` PASS (2 tests); `flutter build apk --debug` PASS. No Flutter source was changed.
- `schema_migrations` currently records versions `003_cms_catalog` through `006_payments_staff_notifications`, but not 000?002. Existing tables/data are present. This ledger mismatch needs an owner-reviewed reconciliation plan; migrations were not rerun.

- Live `SHOW TABLES` still reports 25 tables; key record counts remain services 3, enquiries 4, quotes 4, bookings 5. Migration ledger reports only versions 003?006.

## PHP syntax file inventory

All 53 PHP files below passed php -l after the audit changes:

- `admin/bookings.php`
- `admin/dashboard.php`
- `admin/enquiries.php`
- `admin/includes/db.php`
- `admin/includes/nav.php`
- `admin/includes/ui.php`
- `admin/login.php`
- `admin/logout.php`
- `admin/manage.php`
- `admin/operations.php`
- `admin/quotes.php`
- `admin/upload.php`
- `api/index.php`
- `backend/api/auth/login.php`
- `backend/api/auth/logout.php`
- `backend/api/enquiries.php`
- `backend/api/services.php`
- `backend/config/bootstrap.php`
- `backend/config/db.php`
- `backend/repositories/BookingRepository.php`
- `backend/repositories/CmsRepository.php`
- `backend/repositories/OperationalRepository.php`
- `backend/repositories/PricingRepository.php`
- `backend/scripts/create_admin.php`
- `backend/scripts/reset_admin_password.php`
- `backend/services/PricingEngine.php`
- `config/bootstrap.php`
- `config/db.php`
- `config/env.php`
- `config/rate_limit.php`
- `includes/helpers.php`
- `includes/locales.php`
- `public/404.php`
- `public/500.php`
- `tests/php/pricing_engine_test.php`
- `tests/php/runtime_config_test.php`
- `tests/php/static_api_test.php`
- `website/contact.php`
- `website/faq.php`
- `website/how-it-works.php`
- `website/includes/footer.php`
- `website/includes/header.php`
- `website/includes/navbar.php`
- `website/index.php`
- `website/page.php`
- `website/quote.php`
- `website/robots.php`
- `website/service.php`
- `website/service-area.php`
- `website/service-areas.php`
- `website/services.php`
- `website/sitemap.php`
- `website/tracking.php`

## Continued audit session — 2026-10-21

- The live database remained read-only. All HTTP integration mutations used `roomshift_restore_test_20261021_audit01`, restored and count-verified before testing. It now contains synthetic test users/services/enquiries/quotes/bookings and remains unmigrated. See `migrations/README.md` for dump checksum and restore evidence.
- Final read-only snapshot from `tests/php/read_live_snapshot.php`: live `roomshift_db` still has 25 tables; services 3, enquiries 4, quotes 4, bookings 5; ledger versions remain 003–006. No migration/reset ran.
- Added isolated HTTP tests for envelope shape, role boundaries, enquiry/booking idempotency conflicts, quote/booking capability ownership, pricing authority, discount rejection, legal/illegal status changes, rate limits, CSRF, upload MIME validation, SQL injection payload handling and HTML escaping. Result: 30/30 passed. A two-process booking race and admin actor history query also passed.
- All 58 PHP files passed `php -l`; `config/bootstrap.php` booted; pricing (7), runtime-config (7), and static API (8) assertions passed. Root and published OpenAPI documents have matching SHA-256 hashes.
- Flutter now sends enquiry/quote capability tokens, avoids inventing a confirmed status/local booking time, and requires an explicit release API URL. flutter analyze --no-pub had no issues, flutter test --no-pub passed 3/3, and the debug APK build succeeded. Historical emulator results predate the token requirement.
- Payment provider processing, partner identity/dispatch policy, customer-account history, OTP/proof lifecycle, notification delivery, and staging/production remain blocked by missing business rules, providers, credentials, or hosting. No deployment or live database migration was performed.

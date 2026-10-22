# RoomShift local application

Core PHP, PDO, MySQL/MariaDB, Bootstrap 5, and OpenAPI 3. The existing XAMPP project lives at `C:\xampp\htdocs\roomshift\roomshift`; its public URL is `http://localhost/roomshift/` and API base is `http://localhost/roomshift/api/`.

## Windows + XAMPP setup

1. Start Apache and MySQL in XAMPP Control Panel.
2. Copy `.env.example` to `.env` in this directory. `config/env.php` reads `.env`; process environment variables take precedence. `.env` is ignored by Git. Use unique local secrets for the rate limit key and any enabled provider.
3. For a new, empty local database only, create `roomshift_db` and import `database/roomshift_db.sql`. Never import over an existing database. For an existing database, take a dump backup before each migration.
4. Apply versioned migrations `database/migrations/001_backend_auth_api.sql` through `006_payments_staff_notifications.sql` in order. See [migration instructions](migrations/README.md). Migration files are additive and preserve existing records.
5. Sign in to the existing local administrator using the credentials in [Default Admin](#default-admin-local-only). For a new administrator account, set a private initial password in the process environment, then run:

   ```powershell
   $securePassword = Read-Host "Initial admin password" -AsSecureString
   $env:ROOMSHIFT_INITIAL_ADMIN_PASSWORD = [System.Net.NetworkCredential]::new("", $securePassword).Password
   C:\xampp\php\php.exe backend\scripts\create_admin.php myadmin admin
   Remove-Item Env:ROOMSHIFT_INITIAL_ADMIN_PASSWORD
   ```

   The existing legacy account username `admin` and its password hash are retained during migration; the password is not exposed or replaced. To reset a known account, use the reset script below with the same environment variable.

   ```powershell
   C:\xampp\php\php.exe backend\scripts\reset_admin_password.php myadmin
   ```

## Default Admin (local only)

- Username: `admin`
- Password: `Admin@123`

These credentials are for the local XAMPP database only. Change the password before exposing this installation to any shared or public network. `reset_admin_password.php` normally requires at least 12 characters; the short-password override is accepted only when `ROOMSHIFT_APP_ENV=local` and `ROOMSHIFT_ALLOW_SHORT_LOCAL_PASSWORD=1` are both set in the CLI process.

6. Open `http://localhost/roomshift/`, `http://localhost/roomshift/admin/login.php`, `http://localhost/roomshift/api/services`, and `http://localhost/roomshift/sitemap.xml`.

## API and security

- OpenAPI 3 contract: [openapi.yaml](openapi.yaml), also available at [docs/api/openapi.yaml](docs/api/openapi.yaml).
- API responses use `{ "success": true|false, "data": ..., "message": "...", "error_code": null|"..." }`.
- Database access uses the shared PDO connection from `config/db.php`; SQL values are prepared and bound.
- Quotes are calculated by the server from persisted pricing rules. Booking transitions use a locked state machine and audit history.
- Forms in admin use session-backed CSRF. Public create endpoints use database rate limits and idempotency keys.
- Online payment is a pending placeholder until a real provider is selected and integrated. Notification rows are queued but no provider delivery is claimed. Set provider secrets only after configuration and sandbox verification.
- Uploaded media is limited to validated JPEG, PNG, or WebP images up to 5 MB and stored under `assets/uploads/`.

## Public experience

Services, areas, FAQs, pages, banners, and offers are loaded from MySQL through the CMS repository. The public quote form creates an enquiry, requests a server calculation, and can create an idempotent booking. A private tracking code is required to view booking status.

The available locale dictionaries are in `includes/locales.php`. Localized CMS copy and additional translations can be managed as the business approves them.

## Flutter customer app (Phases 16–20)

The Flutter 3.x customer app lives in `C:\xampp\htdocs\roomshift\flutter_app` and consumes the existing public API. From PowerShell:

```powershell
cd C:\xampp\htdocs\roomshift\flutter_app
flutter pub get
dart run build_runner build
flutter run -d emulator-5554
```

The Android emulator defaults to `http://10.0.2.2/roomshift/api/`; Flutter web defaults to `http://localhost/roomshift/api/`. Override the URL without adding secrets to source code:

```powershell
flutter run --dart-define=ROOMSHIFT_API_BASE_URL=http://10.0.2.2/roomshift/api/
flutter run -d chrome --dart-define=ROOMSHIFT_API_BASE_URL=http://localhost/roomshift/api/
```

For a same-origin web deployment, build with `flutter build web --base-href /roomshift/flutter_app/build/web/` so XAMPP serves the compiled app beneath the existing RoomShift host. The app caches only catalog data and device-local booking summaries in SharedPreferences; private tracking codes use platform secure storage. There is no customer-authenticated booking-list endpoint, so “My bookings” shows bookings saved on this device. Prices are always displayed from the server quote response.

Run quality checks from the Flutter directory with `flutter analyze` and `flutter build apk --debug`.

Flutter Phase 16–20 verification on 2026-10-21: `flutter analyze` passed with no issues, the two model tests passed, and `flutter build apk --debug` succeeded. The Medium Phone API 37 emulator loaded all 3 services, submitted an enquiry, displayed the server quote, created a booking, and rendered its live tracking history. The test created enquiry 4, quote 4, booking 5; the preceding persisted records (including enquiry 3, quote 3, and booking 4) were retained. Database check after the flow: 25 tables; services 3, enquiries 4, quotes 4, bookings 5. No database reset or migration was run for the Flutter work.

## Migrations and local backups

Migrations and verified pre-migration backup paths are tracked in [migrations/README.md](migrations/README.md). Backups are stored outside the web root in the current user's temp directory. Keep an additional backup copy according to your local data-retention policy.

## Final acceptance record (2026-10-21 15:33 IST)

| Gate | Result | Evidence |
| --- | --- | --- |
| A — Foundation | PASS | PDO configuration, environment loading, shared API envelope, auth/RBAC, CSRF, rate limiting, and syntax checks passed in the Gate A acceptance. |
| B — CMS/catalog and pricing | PASS | Additive migrations 003/004, CMS routes, server-side quote calculation, and preserved catalog data passed live checks. |
| C — lifecycle and final hardening | PASS | Existing `admin` user ID 1 signed in through the CSRF-protected login form. The authenticated dashboard returned 200 and showed database counts: services 3, enquiries 2, quotes 2, bookings 3. Booking 3 status history records `changed_by=1` (`admin`). Live enquiry, quote, booking, tracking, API envelope, sitemap, `.env` denial, carousel configuration, and syntax checks passed. |

All Gates A, B, and C passed by 2026-10-21 15:33 IST. Gate C database counts after acceptance: services 3, enquiries 2, quotes 2, bookings 3. The extra enquiry, quote, and booking are the live acceptance records. No acceptance data was deleted. The 005 and 006 pre-migration backups remain at the paths listed in `migrations/README.md`.

## PRD audit and production readiness

The dated Gate A/B/C row above is the historical acceptance snapshot from 2026-10-21 15:33 IST. A later Flutter acceptance added another enquiry, quote, and booking and preserved earlier rows. The latest read-only DB snapshot recorded in [PRD audit baseline](docs/PRD_AUDIT_BASELINE.md) is services 3, enquiries 4, quotes 4, bookings 5. No DB reset or migration was run for the PRD audit.

See the [PRD traceability matrix](docs/PRD_TRACEABILITY.md) for phase-by-phase status and the [production readiness work queue](docs/PRODUCTION_READINESS_TASKS.md) for remaining owner decisions and release gates. This local XAMPP installation is not certified production ready. Provider delivery/payment, OTP/proof workflow verification, partner/dispatch policies, backup restoration, and staging/production deployment remain outstanding. The application now rejects production configuration unless HTTPS, secure cookies, disabled debug, a unique rate-limit key, and a nonempty DB password are set. Admin login also requires passwords of at least 12 characters in production; the documented short demo password is limited to local mode.

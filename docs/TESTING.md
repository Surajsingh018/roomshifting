# Local verification guide

The HTTP integration suite writes synthetic records. It refuses database names that do not start with `roomshift_restore_test_`. Never remove that guard or aim the test server at `roomshift_db`.

## Security and workflow HTTP suite

Run from the application root in PowerShell after creating and verifying a fresh isolated restore. This session used `roomshift_restore_test_20261021_audit01`. The SQL restore itself is intentionally not included here; review `migrations/README.md` and the dump checksum first.

```powershell
$env:ROOMSHIFT_DB_NAME = 'roomshift_restore_test_20261021_audit01'
$env:ROOMSHIFT_PUBLIC_TOKEN_KEY = 'a-local-test-only-key-at-least-32-bytes'
$env:ROOMSHIFT_RATE_LIMIT_KEY = 'another-local-test-key-at-least-32-bytes'
$fixture = php tests/php/integration_fixture_setup.php
[System.IO.File]::WriteAllText((Join-Path $env:TEMP 'roomshift_fixture_audit.json'), $fixture, [System.Text.UTF8Encoding]::new($false))
php -S 127.0.0.1:8765 -t . tests/php/test_router.php
```

In a second PowerShell terminal:

```powershell
python tests/php/security_workflow_test.py
php tests/php/booking_actor_check.php
php tests/php/pricing_engine_test.php
php tests/php/runtime_config_test.php
php tests/php/static_api_test.php
```

The fixture setup clears rate-limit/login-attempt rows in the isolated clone to make rate-limit checks repeatable. The suite covers catalog envelope and parameterized search, stored HTML escaping, API role checks, enquiry/quote/booking ownership and idempotency, one legal and one illegal booking transition, a concurrent booking race using two CLI workers, admin login/CSRF, upload MIME rejection, and public enquiry throttling. It does not represent a complete penetration test or prove production deployment safety.

## Flutter checks

From `C:\xampp\htdocs\roomshift\flutter_app`:

```powershell
dart run build_runner build
flutter analyze --no-pub
flutter test --no-pub
flutter build apk --debug --no-pub
```

Non-debug builds require `--dart-define=ROOMSHIFT_API_BASE_URL=https://<approved-host>/roomshift/api/`.

## Evidence recorded on 2026-10-21

- PHP syntax: 58 files passed `php -l`; `config/bootstrap.php` boot check passed.
- Isolated HTTP suite: 30/30 assertions passed; concurrent booking and status-history actor checks passed.
- PHP unit/config/static checks: pricing 7, runtime config 7, static API 8 passed.
- Flutter analyze had no issues; Flutter tests 3/3 passed; debug APK build succeeded.
- No migrations ran and no test command targeted the live `roomshift_db`.

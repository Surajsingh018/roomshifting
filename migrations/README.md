# RoomShift database migrations

Versioned SQL files live in `database/migrations/`; this instructions file lives at `migrations/README.md`. There is currently **no migration runner**. Migrations are manual SQL scripts, and DDL auto-commits in MariaDB. Do not replay scripts based only on file order or `IF NOT EXISTS` clauses: those clauses do not reconcile existing tables.

## Existing databases

Do not import `database/roomshift_db.sql` over an existing database and do not replay migration files blindly. Read [the migration reconciliation plan](../docs/MIGRATION_RECONCILIATION_PLAN.md) first. In the local `roomshift_db` ledger only versions 003?006 are recorded. Versions 000?002 are not recorded; the live schema is a legacy starter branch and overlaps those scripts. The plan documents the exact schema differences and proposed SQL. No reconciliation SQL has been approved or applied.

Before an approved schema change, take a fresh dump outside the web root, verify its nonzero size and SHA-256, restore it into a new isolated database, compare schema and record counts, and test application behavior against that isolated copy. DDL statements cannot be rolled back with a transaction. Stop on any failed statement; do not write a version marker until all planned DDL and post-checks succeed. A live restore would overwrite changes after the dump and requires separate explicit approval.

## New empty installations

`database/roomshift_db.sql` is a bootstrap import for a genuinely empty local database only; it is not a migration or a safe repair tool. **The current migration sequence has a clean-install defect:** `005_booking_lifecycle.sql` copies legacy accounts from `admins` without first checking that the table exists, while `000_initial_schema.sql` does not create `admins`. Resolve this in a reviewed new-install path before advertising a clean install. Do not create or import an `admins` table into the existing live database just to get past this statement.

Admin provisioning uses `php backend/scripts/create_admin.php <username> admin`; supply a private password via `ROOMSHIFT_INITIAL_ADMIN_PASSWORD`, not a command-line argument.

## Migration files

- `000_initial_schema.sql`: fresh-install baseline; `CREATE TABLE IF NOT EXISTS` is not a schema reconciler.
- `001_backend_auth_api.sql`: auth and API rate-limit structures/ledger marker.
- `002_legacy_schema_compat.sql`: legacy compatibility procedure. It creates, calls, and drops a procedure and is not safe for blind replay.
- `003_cms_catalog.sql`: CMS/catalog tables and catalog fields.
- `004_pricing_quotes.sql`: pricing, enquiry idempotency, and quote-calculation fields.
- `005_booking_lifecycle.sql`: users/staff migration, booking lifecycle, assignment and history structures.
- `006_payments_staff_notifications.sql`: operational scaffolding; no provider integration is enabled by this schema alone.

## Historical pre-migration backups

The following legacy backups were created before migrations 003?006 and verified non-empty. Their SHA-256 values were checked during this audit; keep them private because they may contain customer records:

- Before 003: `C:\Users\ankit\AppData\Local\Temp\roomshift_db_before_003_20261021_115754.sql` (5,982 bytes; SHA-256 `B71D31731FC59038FED582903F557DC9CE991C8ABF23F8524B507DFC43C28425`).
- Before 004: `C:\Users\ankit\AppData\Local\Temp\roomshift_db_before_004_20261021_120303.sql` (13,215 bytes; SHA-256 `BFFAC7BEDD4E4181E8B2B1AB3D316FB4F35651CA6CB56F38CD2923A5476D6823`).
- Before 005: `C:\Users\ankit\AppData\Local\Temp\roomshift_db_before_005_20261021_120927.sql` (18,848 bytes; SHA-256 `D88A4FF72B8ED49F16DBD164953B87B87E33CD8528055E1F7AC7E89A6C1B7FF4`).
- Before 006: `C:\Users\ankit\AppData\Local\Temp\roomshift_db_before_006_20261021_122307.sql` (26,824 bytes; SHA-256 `623D85138B56C23B80B2497D60C12E23499F21BE0B35E2B77651236C1F420432`).

## Isolated restoration verification ? 2026-10-21

A fresh current-state dump was created with XAMPP `mysqldump --single-transaction --quick --routines --triggers --events roomshift_db` at `C:\Users\ankit\AppData\Local\Temp\roomshift_db_audit_snapshot_20261021.sql` (42,005 bytes; SHA-256 `7e667b16b2fca7f9edb19f7a0d3df7bd1ccff1c0c89ddf7f3ec73c554bfcc608`). It was imported into the previously absent isolated database `roomshift_restore_test_20261021_audit01`. Initial verification reported 25 tables and counts of services 3, enquiries 4, quotes 4, bookings 5. The clone was subsequently used for isolated security/workflow tests and contains synthetic test rows; it remains unmigrated and was not used as a source for or written back to `roomshift_db`. A complete application recovery/rollback drill has not yet been performed.

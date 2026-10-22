# Migration reconciliation plan — 2026-10-21

## Scope and safety

This is an analysis and proposed plan only. No schema or application-data SQL was executed against `roomshift_db`. The approved restoration test created a separate database, `roomshift_restore_test_20261021_audit01`, from a verified dump. It remains unmigrated; the subsequent authorized integration suite added synthetic test users, enquiries, quotes, bookings, and a service to that clone. Do not run the proposed SQL against the live database until this plan has been reviewed and explicitly approved.

There is no migration runner in the repository. The SQL files are manually applied. Each migration writes its ledger row as its final statement, but MariaDB DDL auto-commits. A ledger row is evidence the script reached that final statement; it is not a transactional guarantee. `CREATE TABLE IF NOT EXISTS` does not reconcile an already existing table's columns, indexes, or constraints.

## Evidence collected

Read `database/migrations/000_initial_schema.sql` through `006_payments_staff_notifications.sql`, `database/roomshift_db.sql`, `migrations/README.md`, all application migration references, the live migration ledger, `information_schema` columns/indexes/foreign keys, and `SHOW CREATE TABLE` for core relationship tables. No migration runner was found.

The live ledger contains only:

- `003_cms_catalog`
- `004_pricing_quotes`
- `005_booking_lifecycle`
- `006_payments_staff_notifications`

All seven migration files exist. The 000–002 ledger rows are absent. The current database is a legacy starter-schema branch, not a fresh install of `000_initial_schema.sql`: it retains `admins` and `api_login_attempts`; `services.id` and `enquiries.id` are signed `INT`; `services.base_price` is nullable; `api_tokens` retains `admin_id` and its legacy admin FK; and the initial-schema `idempotency_keys` table is absent. The later markers and live table shapes confirm that the catalog, pricing, lifecycle, and operational migrations were applied after that legacy baseline.

The schema is structurally sufficient for the existing application paths exercised so far. Existing 003–006 markers match their main tables and columns. The current code does not reference `enquiries.assigned_to`, `services.meta_description`, `postal_codes`, or `idempotency_keys`; those are legacy/fresh-install schema differences, not safe reasons to replay 000–002. The 005 compatibility logic added a nullable `api_tokens.user_id` and its index while preserving the old `admin_id` relation. A user FK is not present. The core child/parent IDs also differ in width/sign across legacy tables, so blindly adding all initial-schema FKs is unsafe.

All current tables use InnoDB. Read-only orphan counts were zero for enquiry→service, pricing-rule→service, quote→enquiry, booking→quote, booking-history→booking, tracking-event→booking and non-null API-token user→user. These counts are a snapshot only; they can change and must be repeated immediately before any approved constraint migration.

The current live DB is 25 tables: `admins`, `api_login_attempts`, `api_rate_limits`, `api_tokens`, `banners`, `booking_status_history`, `bookings`, `enquiries`, `faqs`, `login_attempts`, `notifications`, `offers`, `pages`, `partners`, `payments`, `pricing_rules`, `quotes`, `reviews`, `schema_migrations`, `service_areas`, `services`, `staff`, `staff_assignments`, `tracking_events`, and `users`.

## Per-migration determination

| Version | Determination | Evidence / gap |
| --- | --- | --- |
| 000_initial_schema | Not recorded; fresh baseline was not applied as written | No marker; `idempotency_keys` absent; legacy tables/column types remain. Do not replay: `IF NOT EXISTS` would leave divergent existing tables unchanged. |
| 001_backend_auth_api | Not recorded; partially superseded by legacy compatibility and 005 | No marker. `login_attempts`/`api_rate_limits` exist and tokens work, but API token schema remains nullable/hybrid (`admin_id` plus `user_id`), not the fresh-install `NOT NULL` user FK. |
| 002_legacy_schema_compat | Not recorded and not fully applied | No marker; `enquiries.assigned_to`, `services.meta_description`, and `fk_api_tokens_user` are absent. Other portions overlap with 003 and 005. Script creates/calls/drops a stored procedure and is not safe for blind replay. |
| 003_cms_catalog | Recorded/applied | Marker present; CMS tables/columns/indexes exist. Legacy service key/base-price types remain by design of `CREATE/ALTER IF NOT EXISTS`. |
| 004_pricing_quotes | Recorded/applied | Marker present; pricing rules, enquiry idempotency and movement fields, quote calculation fields/indexes exist. |
| 005_booking_lifecycle | Recorded/applied | Marker present; users/staff/assignments, booking state/idempotency/tracking fields, booking history and needed FKs exist. Legacy API token columns remain nullable/hybrid. |
| 006_payments_staff_notifications | Recorded/applied | Marker present; payments, queue, reviews, partners, tracking events and OTP/proof columns exist. Provider workflows remain intentionally unconfigured. |

Historical application of 000–002 cannot be proven from the current DB alone. The conclusion is limited to ledger evidence and present-day schema state; do not invent applied timestamps or insert old markers to make the ledger appear complete.

## Proposed reconciliation SQL — not approved or executed

Do not add markers for 000–002. Do not modify rows to force a historical narrative. The clean path is a new, additive `007_legacy_relationships.sql` that records only changes actually applied by that new migration. It should be prepared only after the preconditions below are met and tested on an isolated restored copy.

Proposed exact statements, in this order:

```sql
-- Preconditions: fresh backup; repeat orphan counts; confirm no child FK blocks
-- the parent-width changes; take the application offline for this short DDL window.
ALTER TABLE services
  MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;
ALTER TABLE enquiries
  MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT;

-- Parent/child types now align (all current orphan counts must remain zero).
ALTER TABLE enquiries
  ADD CONSTRAINT fk_enquiries_service_007
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE SET NULL;
ALTER TABLE pricing_rules
  ADD CONSTRAINT fk_pricing_rules_service_007
  FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT;
ALTER TABLE quotes
  ADD CONSTRAINT fk_quotes_enquiry_007
  FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE RESTRICT;
ALTER TABLE bookings
  ADD CONSTRAINT fk_bookings_quote_007
  FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT;
ALTER TABLE bookings
  ADD CONSTRAINT fk_bookings_enquiry_007
  FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE SET NULL;
ALTER TABLE partners
  ADD CONSTRAINT fk_partners_area_007
  FOREIGN KEY (service_area_id) REFERENCES service_areas(id) ON DELETE SET NULL;
ALTER TABLE api_tokens
  ADD CONSTRAINT fk_api_tokens_user_007
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

-- Insert this only after all ALTER statements and post-checks succeed.
INSERT INTO schema_migrations(version)
VALUES ('007_legacy_relationships');
```

This proposed sequence is not yet execution-ready: widths/compatibility and MariaDB locking behavior must first pass on the restored copy; the backup/import and full schema diff must be reviewed. The ALTER statements are not transactional. If any statement fails, stop, do not insert the ledger marker, preserve the failed test DB/logs for diagnosis, and restore the isolated DB from the backup before retrying. For an approved live run, take and hash a new current backup immediately beforehand; if post-checks fail, keep writes disabled and restore the backup to a new recovery DB for reconciliation before choosing a live recovery path. Never drop the original/live DB.

The proposal intentionally omits `enquiries.assigned_to`, `services.meta_description`, `postal_codes`, and `idempotency_keys`; they are not used by current application code. Add them only with an approved feature requirement and tests. No constraint is proposed for provider-specific or unresolved partner/dispatch rules.

## Required pre-approval checks

1. Validate the proposed DDL on an isolated restore and capture before/after `SHOW CREATE TABLE`, index/FK lists, row counts, max IDs and application smoke results.
2. Repeat all orphan checks and inspect `information_schema` for existing constraints immediately before each proposed `ADD CONSTRAINT`.
3. Confirm no concurrent writes and measure DDL lock duration against the target hosting engine/version.
4. Review the exact 007 file and backup checksum, then obtain explicit user approval before applying it to `roomshift_db`.
5. After execution, verify the `007` marker appears last, all expected constraints are present, and existing record counts/IDs are unchanged.

## Recovery considerations

MariaDB DDL causes implicit commits; transaction rollback is not a recovery method. The recovery mechanism is a verified full backup plus restore into a separate database first. A live restore would replace data created after the backup, so it requires a separate explicit approval and a reconciled write-freeze/cutover plan. Keep the pre-change dump, checksum, command log and post-change schema snapshot outside the web root with restricted access and an agreed retention period.

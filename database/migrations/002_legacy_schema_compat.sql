-- Additive compatibility for the early RoomShift starter database.
-- Safe to run after 000_initial_schema.sql; no tables or records are dropped.
DELIMITER $$
CREATE PROCEDURE roomshift_apply_legacy_compat()
BEGIN
  -- Earlier starter builds stored token ownership in admins. Add the new
  -- nullable user link and revoke old tokens instead of trusting stale auth.
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens' AND COLUMN_NAME = 'user_id'
  ) THEN
    ALTER TABLE api_tokens ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER admin_id;
    UPDATE api_tokens SET revoked_at = COALESCE(revoked_at, NOW());
  END IF;
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens' AND INDEX_NAME = 'idx_api_tokens_user'
  ) THEN ALTER TABLE api_tokens ADD INDEX idx_api_tokens_user (user_id); END IF;
  IF EXISTS (
    SELECT 1 FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens'
  ) AND NOT EXISTS (
    SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'api_tokens' AND CONSTRAINT_NAME = 'fk_api_tokens_user'
  ) THEN
    ALTER TABLE api_tokens ADD CONSTRAINT fk_api_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'slug'
  ) THEN
    ALTER TABLE services ADD COLUMN slug VARCHAR(140) NULL AFTER name;
    UPDATE services SET slug = CONCAT('service-', id) WHERE slug IS NULL OR slug = '';
    ALTER TABLE services MODIFY slug VARCHAR(140) NOT NULL;
    ALTER TABLE services ADD UNIQUE KEY uq_services_slug (slug);
  END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'seo_title'
  ) THEN ALTER TABLE services ADD COLUMN seo_title VARCHAR(255) NULL; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'meta_description'
  ) THEN ALTER TABLE services ADD COLUMN meta_description VARCHAR(320) NULL; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'canonical_url'
  ) THEN ALTER TABLE services ADD COLUMN canonical_url VARCHAR(500) NULL; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'og_image'
  ) THEN ALTER TABLE services ADD COLUMN og_image VARCHAR(500) NULL; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'services' AND COLUMN_NAME = 'updated_at'
  ) THEN ALTER TABLE services ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP; END IF;

  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'email'
  ) THEN ALTER TABLE enquiries ADD COLUMN email VARCHAR(190) NULL AFTER name; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'status'
  ) THEN ALTER TABLE enquiries ADD COLUMN status ENUM('new','in_progress','closed') NOT NULL DEFAULT 'new'; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'assigned_to'
  ) THEN ALTER TABLE enquiries ADD COLUMN assigned_to BIGINT UNSIGNED NULL; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND COLUMN_NAME = 'updated_at'
  ) THEN ALTER TABLE enquiries ADD COLUMN updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP; END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND INDEX_NAME = 'idx_enquiries_status_created'
  ) THEN ALTER TABLE enquiries ADD INDEX idx_enquiries_status_created (status, created_at); END IF;
  IF NOT EXISTS (
    SELECT 1 FROM information_schema.REFERENTIAL_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'enquiries' AND CONSTRAINT_NAME = 'fk_enquiries_assignee'
  ) THEN
    ALTER TABLE enquiries ADD CONSTRAINT fk_enquiries_assignee FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL;
  END IF;
END$$
DELIMITER ;

CALL roomshift_apply_legacy_compat();
DROP PROCEDURE roomshift_apply_legacy_compat;
INSERT IGNORE INTO schema_migrations(version) VALUES ('002_legacy_schema_compat');

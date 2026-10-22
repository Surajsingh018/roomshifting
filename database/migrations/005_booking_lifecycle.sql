-- Phase 08 booking lifecycle. Additive and safe for the existing catalog/enquiry/quote data.
CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(64) PRIMARY KEY,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Align legacy admin accounts with the shared RBAC user model without changing
-- their password hashes. New administrators continue to be provisioned by CLI.
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username VARCHAR(50) NOT NULL UNIQUE,
  email VARCHAR(190) NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('admin','staff') NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_users_role_active (role,is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO users (id, username, password_hash, role, is_active)
SELECT id, username, password, IF(role='superadmin','admin','staff'), 1
FROM admins WHERE username IS NOT NULL AND password IS NOT NULL AND password <> '';

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip_hash CHAR(64) NOT NULL,
  attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_ip_time (ip_hash,attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL;
ALTER TABLE api_tokens ADD COLUMN IF NOT EXISTS admin_id INT NULL;
ALTER TABLE api_tokens MODIFY COLUMN admin_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_api_tokens_user ON api_tokens (user_id);
UPDATE api_tokens t JOIN users u ON u.id=t.admin_id SET t.user_id=u.id WHERE t.user_id IS NULL;

CREATE TABLE IF NOT EXISTS staff (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL UNIQUE,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(25) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_staff_status_name (status,name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bookings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  quote_id BIGINT UNSIGNED NOT NULL,
  enquiry_id BIGINT UNSIGNED NULL,
  booking_ref VARCHAR(32) NOT NULL UNIQUE,
  idempotency_key VARCHAR(128) NULL,
  tracking_code VARCHAR(32) NULL,
  customer_name VARCHAR(120) NOT NULL,
  customer_email VARCHAR(190) NULL,
  customer_phone VARCHAR(25) NOT NULL,
  status ENUM('enquiry','quote','confirmed','assigned','in_transit','completed','cancelled') NOT NULL DEFAULT 'quote',
  assigned_staff_id BIGINT UNSIGNED NULL,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bookings_idempotency_key (idempotency_key),
  UNIQUE KEY uq_bookings_tracking_code (tracking_code),
  INDEX idx_bookings_status_date (status,created_at),
  INDEX idx_bookings_enquiry (enquiry_id),
  CONSTRAINT fk_bookings_staff_005 FOREIGN KEY (assigned_staff_id) REFERENCES staff(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE bookings ADD COLUMN IF NOT EXISTS enquiry_id BIGINT UNSIGNED NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(128) NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS tracking_code VARCHAR(32) NULL;
ALTER TABLE bookings ADD COLUMN IF NOT EXISTS assigned_staff_id BIGINT UNSIGNED NULL;
ALTER TABLE bookings MODIFY COLUMN status ENUM('enquiry','quote','confirmed','assigned','in_transit','completed','cancelled') NOT NULL DEFAULT 'quote';
CREATE UNIQUE INDEX IF NOT EXISTS uq_bookings_idempotency_key ON bookings (idempotency_key);
CREATE UNIQUE INDEX IF NOT EXISTS uq_bookings_tracking_code ON bookings (tracking_code);

CREATE TABLE IF NOT EXISTS staff_assignments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  staff_id BIGINT UNSIGNED NOT NULL,
  assigned_by BIGINT UNSIGNED NULL,
  status ENUM('assigned','accepted','rejected','reassigned','completed') NOT NULL DEFAULT 'assigned',
  note VARCHAR(500) NULL,
  assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_staff_assignments_booking (booking_id,assigned_at),
  INDEX idx_staff_assignments_staff_status (staff_id,status),
  CONSTRAINT fk_staff_assignments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_staff_assignments_staff FOREIGN KEY (staff_id) REFERENCES staff(id),
  CONSTRAINT fk_staff_assignments_user FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS booking_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  booking_id BIGINT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL,
  to_status VARCHAR(30) NOT NULL,
  changed_by BIGINT UNSIGNED NULL,
  note VARCHAR(500) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_booking_history_booking_time (booking_id,created_at),
  CONSTRAINT fk_booking_history_booking_005 FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE,
  CONSTRAINT fk_booking_history_user_005 FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO schema_migrations(version) VALUES ('005_booking_lifecycle');

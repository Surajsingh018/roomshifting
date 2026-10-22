-- Phase 07 pricing and quote persistence. Additive: existing customer records remain intact.
-- Compatible with both the starter enquiry table and the foundation schema.

CREATE TABLE IF NOT EXISTS pricing_rules (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  service_id BIGINT UNSIGNED NOT NULL,
  base_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  per_km DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  per_floor DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  packing_charges DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gst_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  min_charge DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pricing_rules_service (service_id),
  INDEX idx_pricing_rules_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS enquiries (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(25) NOT NULL,
  message TEXT NULL,
  idempotency_key VARCHAR(128) NULL,
  service_id BIGINT UNSIGNED NULL,
  from_address VARCHAR(500) NULL,
  to_address VARCHAR(500) NULL,
  floors INT UNSIGNED NOT NULL DEFAULT 0,
  distance_km DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  packing_required TINYINT(1) NOT NULL DEFAULT 0,
  inventory JSON NULL,
  status ENUM('new','in_progress','closed') NOT NULL DEFAULT 'new',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_enquiries_idempotency_key (idempotency_key),
  INDEX idx_enquiries_service_created (service_id, created_at),
  INDEX idx_enquiries_status_created (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS idempotency_key VARCHAR(128) NULL;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS email VARCHAR(190) NULL;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS service_id BIGINT UNSIGNED NULL;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS from_address VARCHAR(500) NULL;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS to_address VARCHAR(500) NULL;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS floors INT UNSIGNED NOT NULL DEFAULT 0;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS distance_km DECIMAL(8,2) NOT NULL DEFAULT 0.00;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS packing_required TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS inventory JSON NULL;
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS status ENUM('new','in_progress','closed') NOT NULL DEFAULT 'new';
ALTER TABLE enquiries ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
CREATE UNIQUE INDEX IF NOT EXISTS uq_enquiries_idempotency_key ON enquiries (idempotency_key);
CREATE INDEX IF NOT EXISTS idx_enquiries_service_created ON enquiries (service_id, created_at);

CREATE TABLE IF NOT EXISTS quotes (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  enquiry_id BIGINT UNSIGNED NOT NULL,
  calculation_breakdown JSON NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  gst DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  valid_until DATETIME NULL,
  status ENUM('draft','sent','accepted','rejected','expired') NOT NULL DEFAULT 'draft',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_quotes_enquiry_created (enquiry_id, created_at),
  INDEX idx_quotes_status_valid (status, valid_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE quotes ADD COLUMN IF NOT EXISTS calculation_breakdown JSON NULL;
ALTER TABLE quotes ADD COLUMN IF NOT EXISTS gst DECIMAL(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE quotes ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
CREATE INDEX IF NOT EXISTS idx_quotes_enquiry_created ON quotes (enquiry_id, created_at);

CREATE TABLE IF NOT EXISTS api_rate_limits (
  bucket_key CHAR(64) PRIMARY KEY,
  hits INT UNSIGNED NOT NULL DEFAULT 0,
  window_started_at DATETIME NOT NULL,
  INDEX idx_api_rate_limit_window (window_started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Carry each existing service's stored base price into an initial rule. Components
-- remain zero until configured; INSERT IGNORE preserves any existing rule values.
INSERT IGNORE INTO pricing_rules (service_id, base_price)
SELECT id, COALESCE(base_price, 0.00) FROM services;

INSERT IGNORE INTO schema_migrations(version) VALUES ('004_pricing_quotes');

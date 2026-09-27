-- Migration: adds Square payment tracking.
-- Run this if your `bookings` table does not yet have `amount_due_cents`,
-- `payment_status`, or `access_token` columns.
--
--   mysql -u root -p tire_storage < migration_payments.sql

CREATE TABLE IF NOT EXISTS payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    booking_id          INT NOT NULL,
    square_payment_id   VARCHAR(80) NOT NULL,
    amount_cents        INT NOT NULL,
    currency            VARCHAR(3) NOT NULL DEFAULT 'CAD',
    status              VARCHAR(30) NOT NULL,
    receipt_url         VARCHAR(255) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Add new columns as nullable/defaulted first so this doesn't fail on existing rows.
ALTER TABLE bookings
    ADD COLUMN amount_due_cents INT NULL AFTER status,
    ADD COLUMN payment_status ENUM('unpaid','paid','failed','refunded') NOT NULL DEFAULT 'unpaid' AFTER amount_due_cents,
    ADD COLUMN access_token CHAR(32) NULL AFTER payment_status;

-- Backfill amount_due_cents for existing bookings from their package + zone.
UPDATE bookings b
JOIN packages p ON p.id = b.package_id
JOIN zones z ON z.id = b.zone_id
SET b.amount_due_cents = p.price_cents + z.surcharge_cents
WHERE b.amount_due_cents IS NULL;

-- Backfill a random access token for any existing bookings (needed since it's UNIQUE NOT NULL).
-- MySQL 8 / MariaDB 10.5+: UUID() is good enough as a per-row unique filler.
UPDATE bookings SET access_token = REPLACE(UUID(), '-', '') WHERE access_token IS NULL;

ALTER TABLE bookings
    MODIFY COLUMN amount_due_cents INT NOT NULL,
    MODIFY COLUMN access_token CHAR(32) NOT NULL,
    ADD CONSTRAINT uq_bookings_access_token UNIQUE (access_token);

-- Existing bookings taken before Square was wired up: mark them as paid
-- outside the system if that's accurate, otherwise leave as 'unpaid' and
-- send those customers a payment link (see README) to settle up.

-- Migration: adds bin number assignment (container + side + 2-digit slot,
-- e.g. AL01) to existing bookings.
--
-- Run this if your `bookings` table does not yet have a `bin_number` column.
--
--   mysql -u root -p tire_storage < migration_bins.sql

ALTER TABLE bookings
    ADD COLUMN bin_number VARCHAR(4) DEFAULT NULL AFTER quantity;

-- Enforce the format at the database level too (MySQL 8.0.16+ / MariaDB
-- 10.2+ actually enforce CHECK constraints; older versions parse but ignore
-- it, so the application still validates this on every save regardless).
ALTER TABLE bookings
    ADD CONSTRAINT chk_bookings_bin_number
    CHECK (bin_number IS NULL OR bin_number REGEXP '^[A-Z][LR][0-9]{2}$');

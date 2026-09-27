-- Migration: adds multi-set booking (up to 3 sets per booking) and normalizes
-- Fleet Bay to the same "per set" pricing model as the other two packages.
--
-- Run this if your `bookings` table does not yet have a `quantity` column.
--
--   mysql -u root -p tire_storage < migration_sets.sql

ALTER TABLE bookings
    ADD COLUMN quantity TINYINT NOT NULL DEFAULT 1 AFTER tire_size;

-- Enforce the 1-3 range at the database level too (MySQL 8.0.16+ / MariaDB
-- 10.2+ actually enforce CHECK constraints; older versions parse but ignore
-- it, so the application still validates this on every booking regardless).
ALTER TABLE bookings
    ADD CONSTRAINT chk_bookings_quantity CHECK (quantity BETWEEN 1 AND 3);

-- Fleet Bay used to bundle 8 tires (two sets) into one fixed price. It's now
-- priced per set like the other packages, and customers choose how many sets
-- (1-3) at checkout. This does not touch price_cents — only the tire_limit
-- label and description change to match the new per-set framing.
UPDATE packages
SET tire_limit = 4,
    tagline = 'Full-service storage, priced the same per set no matter how many vehicles.',
    features = 'Up to 4 tires per set — store 1 to 3 sets\nWash and inspection included\nFree pickup and delivery within Ottawa\nSeparate tagging per vehicle\nPriority pickup scheduling'
WHERE slug = 'fleet-bay';

UPDATE packages
SET features = 'Up to 4 tires per set — store 1 to 3 sets\nIndividually tagged with your name and vehicle\nStacked on pallets, off the ground\n6 months in a climate-buffered container\nText or email when you\'re ready for pickup'
WHERE slug = 'standard-stow';

-- Existing bookings already reflect 1 set at their original price and don't
-- need to change — `quantity` simply defaults to 1 for all of them.

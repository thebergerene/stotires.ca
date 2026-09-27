-- Migration: adds a 4th geographic zone (South of Hunt Club, Saturday pickup)
-- and updates the three package prices.
--
--   mysql -u root -p tire_storage < migration_zone_d_and_prices.sql

INSERT INTO zones (slug, name, areas, surcharge_cents, service_days, weekday_csv, sort_order)
SELECT 'zone-d', 'Zone D — South of Hunt Club', 'South of Hunt Club Rd', 3000, 'Saturday', '6', 4
WHERE NOT EXISTS (SELECT 1 FROM zones WHERE slug = 'zone-d');

-- New per-set prices (in cents): Standard Stow $138, Full Set + Wash $152, Fleet Bay $174.
-- This only changes price_cents going forward — existing bookings keep their
-- original amount_due_cents, which was snapshotted at booking time.
UPDATE packages SET price_cents = 13800 WHERE slug = 'standard-stow';
UPDATE packages SET price_cents = 15200 WHERE slug = 'full-set-wash';
UPDATE packages SET price_cents = 17400 WHERE slug = 'fleet-bay';

-- Migration: adds is_featured and is_active to packages, so which package
-- shows the "MOST BOOKED" badge (and whether a package is visible/bookable
-- at all) becomes admin-configurable instead of hardcoded to a specific slug.
--
--   mysql -u root -p tire_storage < migration_package_config.sql

ALTER TABLE packages
    ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0 AFTER features,
    ADD COLUMN is_active   TINYINT(1) NOT NULL DEFAULT 1 AFTER is_featured;

-- Preserve today's behaviour: Full Set + Wash currently gets the badge because
-- index.php checked for that specific slug. Carry that over as the featured
-- flag so nothing visibly changes until you pick a different one in the admin.
UPDATE packages SET is_featured = 1 WHERE slug = 'full-set-wash';

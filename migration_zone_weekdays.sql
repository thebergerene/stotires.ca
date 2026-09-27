-- Follow-up migration: replaces the old "Core/Suburbs/Outlying" zones with
-- real St-Laurent / Greenbank boundary zones and adds day-of-week enforcement.
--
-- Run this ONLY if you already ran the earlier migration_zones.sql (the one
-- with 'zone-core', 'zone-suburbs', 'zone-outlying'). Fresh installs should
-- just use schema.sql, and installs migrating for the first time should use
-- the current migration_zones.sql — don't run this on top of either of those.
--
--   mysql -u root -p tire_storage < migration_zone_weekdays.sql

ALTER TABLE zones ADD COLUMN weekday_csv VARCHAR(20) NOT NULL DEFAULT '' AFTER service_days;

-- Rename the old zones in place (existing bookings keep pointing at the same
-- zone_id, they just get new names/areas/days/weekday rules).
UPDATE zones SET name = 'Zone A — East of St-Laurent',
                  areas = 'East of St-Laurent Blvd',
                  surcharge_cents = 0,
                  service_days = 'Monday',
                  weekday_csv = '1'
            WHERE slug = 'zone-core';

UPDATE zones SET name = 'Zone B — St-Laurent to Greenbank',
                  areas = 'West of St-Laurent Blvd, east of Greenbank Rd',
                  surcharge_cents = 1500,
                  service_days = 'Tuesday & Wednesday',
                  weekday_csv = '2,3'
            WHERE slug = 'zone-suburbs';

UPDATE zones SET name = 'Zone C — West of Greenbank',
                  areas = 'West of Greenbank Rd',
                  surcharge_cents = 3000,
                  service_days = 'Thursday & Friday',
                  weekday_csv = '4,5'
            WHERE slug = 'zone-outlying';

-- Optional: also rename the slugs for consistency with fresh installs.
UPDATE zones SET slug = 'zone-a' WHERE slug = 'zone-core';
UPDATE zones SET slug = 'zone-b' WHERE slug = 'zone-suburbs';
UPDATE zones SET slug = 'zone-c' WHERE slug = 'zone-outlying';

-- IMPORTANT: existing bookings may have a pickup_date that no longer falls on
-- their zone's allowed weekday now that days changed. Review and adjust these
-- manually — the app will not silently change customer-facing dates for you.
-- (MySQL's DAYOFWEEK() is 1=Sunday..7=Saturday; this converts it to the same
-- ISO 1=Monday..7=Sunday numbering used in weekday_csv.)
SELECT b.id, b.full_name, b.pickup_date, z.name AS zone_name, z.service_days, z.weekday_csv
FROM bookings b
JOIN zones z ON z.id = b.zone_id
WHERE NOT FIND_IN_SET(MOD(DAYOFWEEK(b.pickup_date) + 5, 7) + 1, z.weekday_csv);

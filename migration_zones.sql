-- Migration: adds geographic service zones and pickup/delivery dates.
-- Run this ONLY if you already deployed the earlier version of this site
-- (i.e. your `bookings` table still has a `drop_off_date` column).
-- Fresh installs should just use schema.sql instead — don't run both.
--
--   mysql -u root -p tire_storage < migration_zones.sql

CREATE TABLE IF NOT EXISTS zones (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(40)   NOT NULL UNIQUE,
    name          VARCHAR(80)   NOT NULL,
    areas         VARCHAR(200)  NOT NULL,
    surcharge_cents INT         NOT NULL DEFAULT 0,
    service_days  VARCHAR(80)   NOT NULL,
    weekday_csv   VARCHAR(20)   NOT NULL,
    sort_order    TINYINT       NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO zones (slug, name, areas, surcharge_cents, service_days, weekday_csv, sort_order) VALUES
('zone-a', 'Zone A — East of St-Laurent',      'East of St-Laurent Blvd',                        0,    'Monday',              '1',   1),
('zone-b', 'Zone B — St-Laurent to Greenbank', 'West of St-Laurent Blvd, east of Greenbank Rd',  1500, 'Tuesday & Wednesday', '2,3', 2),
('zone-c', 'Zone C — West of Greenbank',       'West of Greenbank Rd',                            3000, 'Thursday & Friday',   '4,5', 3);

-- Add the new columns first as nullable so the ALTER doesn't fail on existing rows.
ALTER TABLE bookings
    ADD COLUMN zone_id INT NULL AFTER package_id,
    ADD COLUMN delivery_date DATE NULL AFTER drop_off_date;

-- Backfill existing rows: default them to Zone A, and set delivery 6 months after pickup.
UPDATE bookings SET zone_id = (SELECT id FROM zones WHERE slug = 'zone-a') WHERE zone_id IS NULL;
UPDATE bookings SET delivery_date = DATE_ADD(drop_off_date, INTERVAL 6 MONTH) WHERE delivery_date IS NULL;

-- Rename drop_off_date -> pickup_date to match the new field names.
ALTER TABLE bookings CHANGE COLUMN drop_off_date pickup_date DATE NOT NULL;

-- Now that every row has a value, enforce NOT NULL and add the foreign key.
ALTER TABLE bookings
    MODIFY COLUMN zone_id INT NOT NULL,
    MODIFY COLUMN delivery_date DATE NOT NULL,
    ADD CONSTRAINT fk_bookings_zone FOREIGN KEY (zone_id) REFERENCES zones(id);

-- Migration: adds a pickup/delivery address field to bookings, needed for
-- the warehouse scheduler (/admin/schedule.php) to show staff where to go.
--
--   mysql -u root -p tire_storage < migration_address.sql
--
-- Existing bookings will have a NULL address (they were made before this
-- field existed) — the scheduler shows those as "No address on file" so
-- staff know to follow up with the customer.

ALTER TABLE bookings
    ADD COLUMN address VARCHAR(200) DEFAULT NULL AFTER phone;

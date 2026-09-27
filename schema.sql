-- Drydock Tire Storage — database schema
-- Import with: mysql -u root -p tire_storage < schema.sql
-- (create the database first: CREATE DATABASE tire_storage CHARACTER SET utf8mb4;)

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    role          ENUM('admin', 'logistics') NOT NULL DEFAULT 'logistics',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default login: username "admin", password "changeme123" — change this
-- immediately (Admin > My account, once logged in, or via /admin/users.php).
INSERT INTO users (username, password_hash, full_name, role) VALUES
('admin', '$2b$10$kUvT1xgXyaRRX/kufB3cCe5mTZa4d73WBRHvAzwHY.vwu46Fp8NE.', 'Site Admin', 'admin');

CREATE TABLE IF NOT EXISTS packages (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(40)   NOT NULL UNIQUE,
    name          VARCHAR(80)   NOT NULL,
    tire_limit    TINYINT       NOT NULL,
    price_cents   INT           NOT NULL,       -- what's actually charged per set for the 6-month term
    monthly_rate_cents INT      DEFAULT NULL,   -- optional: advertised as "$X/mo per tire" on the card; NULL = just show price_cents as a flat total
    tagline       VARCHAR(160)  NOT NULL,
    features      TEXT          NOT NULL,   -- one feature per line
    is_featured   TINYINT(1)    NOT NULL DEFAULT 0, -- shows the "MOST BOOKED" badge; only one package should have this set
    is_active     TINYINT(1)    NOT NULL DEFAULT 1, -- hidden from the homepage and un-bookable when 0, without breaking past bookings that reference it
    sort_order    TINYINT       NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS zones (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    slug          VARCHAR(40)   NOT NULL UNIQUE,
    name          VARCHAR(80)   NOT NULL,
    areas         VARCHAR(200)  NOT NULL,   -- boundary description shown to the customer
    surcharge_cents INT         NOT NULL DEFAULT 0,
    service_days  VARCHAR(80)   NOT NULL,   -- human label, e.g. "Tuesday & Wednesday"
    weekday_csv   VARCHAR(20)   NOT NULL,   -- ISO-8601 weekday numbers (Mon=1..Sun=7), e.g. "2,3"
    sort_order    TINYINT       NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bookings (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    package_id      INT NOT NULL,
    zone_id         INT NOT NULL,
    full_name       VARCHAR(120) NOT NULL,
    email           VARCHAR(160) NOT NULL,
    phone           VARCHAR(40)  NOT NULL,
    address         VARCHAR(200) DEFAULT NULL,   -- pickup/delivery street address, used by the warehouse scheduler
    vehicle_info    VARCHAR(160) NOT NULL,   -- year/make/model
    tire_size       VARCHAR(60)  DEFAULT NULL,
    quantity        TINYINT NOT NULL DEFAULT 1 CHECK (quantity BETWEEN 1 AND 3), -- number of sets (up to 4 tires each), max 3
    bin_number      VARCHAR(4) DEFAULT NULL CHECK (bin_number IS NULL OR bin_number REGEXP '^[A-Z][LR][0-9]{2}$'), -- e.g. AL01: container A, Left side, bin 01
    pickup_date     DATE NOT NULL,           -- when we collect the tires (or the customer drops off)
    delivery_date   DATE NOT NULL,           -- defaults to 6 calendar months after pickup_date but is customer-editable (server allows 4-8 months)
    notes           TEXT DEFAULT NULL,
    status          ENUM('pending','confirmed','dropped_off','picked_up','cancelled') NOT NULL DEFAULT 'pending',
    amount_due_cents INT NOT NULL,                                       -- (package price x quantity) + zone surcharge, snapshotted at booking time
    payment_status  ENUM('unpaid','paid','failed','refunded') NOT NULL DEFAULT 'unpaid',
    access_token    CHAR(32) NOT NULL UNIQUE,                            -- lets the customer reach their own payment page without logging in
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_bookings_package FOREIGN KEY (package_id) REFERENCES packages(id),
    CONSTRAINT fk_bookings_zone    FOREIGN KEY (zone_id)    REFERENCES zones(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    booking_id          INT NOT NULL,
    square_payment_id   VARCHAR(80) NOT NULL,
    amount_cents        INT NOT NULL,
    currency            VARCHAR(3) NOT NULL DEFAULT 'CAD',
    status              VARCHAR(30) NOT NULL,   -- Square's payment status: COMPLETED, FAILED, etc.
    receipt_url         VARCHAR(255) DEFAULT NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- price_cents is per set (up to 4 tires). Customers choose 1-3 sets at checkout;
-- the total is price_cents x quantity, plus their zone's surcharge (see below).
-- monthly_rate_cents is optional advertising copy only ("$X/mo per tire") and
-- does not feed into billing math anywhere — price_cents is always what's charged.
INSERT INTO packages (slug, name, tire_limit, price_cents, monthly_rate_cents, tagline, features, is_featured, is_active, sort_order) VALUES
('standard-stow', 'Standard Stow', 4, 9600, 400,
 'Bag it, tag it, forget it for six months.',
 'Up to 4 tires per set — store 1 to 3 sets\nIndividually tagged with your name and vehicle\nStacked on pallets, off the ground\n6 months in a climate-buffered container\nText or email when you\'re ready for pickup',
 0, 1, 1),
('full-set-wash', 'Full Set + Wash', 4, 15200, NULL,
 'Same as Standard Stow, plus we clean and inspect before they go on the rack.',
 'Everything in Standard Stow\nTires washed and dried before storage\nTread depth and visible damage logged\nFree re-torque reminder card for pickup day\nPriority pickup scheduling',
 1, 1, 2),
('fleet-bay', 'Fleet Bay', 4, 17400, NULL,
 'Full-service storage, priced the same per set no matter how many vehicles.',
 'Up to 4 tires per set — store 1 to 3 sets\nWash and inspection included\nFree pickup and delivery within Ottawa\nSeparate tagging per vehicle\nPriority pickup scheduling',
 0, 1, 3);

-- Geographic service areas for pickup & delivery scheduling.
-- Pickup day is fixed per zone (see weekday_csv: Mon=1 ... Sun=7) and enforced
-- both client-side (js/main.js) and server-side (book_and_pay.php).
-- surcharge_cents is added to the package price for that zone (0 = included free).
INSERT INTO zones (slug, name, areas, surcharge_cents, service_days, weekday_csv, sort_order) VALUES
('zone-a', 'Zone A — East of St-Laurent',              'East of St-Laurent Blvd',                                  0,    'Monday',              '1',   1),
('zone-b', 'Zone B — St-Laurent to Greenbank',         'West of St-Laurent Blvd, east of Greenbank Rd',            1500, 'Tuesday & Wednesday', '2,3', 2),
('zone-c', 'Zone C — West of Greenbank',               'West of Greenbank Rd',                                     3000, 'Thursday & Friday',   '4,5', 3),
('zone-d', 'Zone D — South of Hunt Club',              'South of Hunt Club Rd',                                    3000, 'Saturday',            '6',   4);

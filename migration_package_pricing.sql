-- Migration: adds an optional "advertised as $X/mo per tire" display field to
-- packages, and switches Standard Stow to the calculated $4/tire/month price.
--
--   mysql -u root -p tire_storage < migration_package_pricing.sql

ALTER TABLE packages
    ADD COLUMN monthly_rate_cents INT DEFAULT NULL AFTER price_cents;

-- Standard Stow: $4/tire/month x 4 tires x 6 months = $96 flat per set,
-- regardless of the exact delivery date the customer picks.
UPDATE packages
SET price_cents = 9600,
    monthly_rate_cents = 400
WHERE slug = 'standard-stow';

-- monthly_rate_cents is display-only (the "$4/mo" wording on the card) and is
-- never used in the actual billing calculation — price_cents remains the
-- amount that's charged, exactly as before. Leaving monthly_rate_cents NULL
-- on the other packages keeps them showing their existing flat-price style.

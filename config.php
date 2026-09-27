<?php
/**
 * Site configuration.
 * Edit these values for your server, then upload config.php alongside the rest of the site.
 * Keep this file OUTSIDE the public web root if your host allows it, or otherwise
 * make sure your web server is not configured to serve raw .php source (it isn't, by default).
 */

// ---- Database ----
define('DB_HOST', 'localhost');
define('DB_NAME', 'mfwzdezf_stotires');
define('DB_USER', 'mfwzdezf_admin');
define('DB_PASS', 'RenMik!00!');
define('DB_CHARSET', 'utf8mb4');

// ---- Site ----
define('SITE_NAME', 'Drydock Tire Storage');
define('SITE_EMAIL', 'hello@drydocktires.example');
define('SITE_PHONE', '(613) 555-0142');
define('SITE_ADDRESS', '14 Container Yard Rd, Ottawa, ON');

// ---- Admin ----
// Change this password before deploying. Generate a new hash with:
//   php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD_HASH', '$2b$10$kUvT1xgXyaRRX/kufB3cCe5mTZa4d73WBRHvAzwHY.vwu46Fp8NE.'); // default: "changeme123"

// ---- Square payments ----
// Get these from the Square Developer Dashboard (developer.squareup.com/apps).
// Use your Sandbox app's values while testing, then switch to the Production
// app's values (and flip SQUARE_ENVIRONMENT) when you're ready to take real payments.
define('SQUARE_ENVIRONMENT', 'sandbox'); // 'sandbox' or 'production'
define('SQUARE_ACCESS_TOKEN', 'EAAAlynH93PAJp3z-DyBnqmT3tdmKXH6Msozw8VncYHglTva99pKAdc-XZRs4zr-');   // secret — server-side only, never expose in HTML/JS
define('SQUARE_APPLICATION_ID', 'sandbox-sq0idb-B9sYQfEJGWsg_21nA5mN8g'); // public, safe in client-side JS
define('SQUARE_LOCATION_ID', 'L2BRJBREN67FD');       // public, safe in client-side JS
define('SQUARE_CURRENCY', 'CAD');
// Square's dated API versioning — check https://developer.squareup.com/reference/square
// for the current version string when you first deploy, and update occasionally.
define('SQUARE_API_VERSION', '2025-01-23');

// ---- Misc ----
date_default_timezone_set('America/Toronto');
session_start();

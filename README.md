#  Ottawa Tire Storage

A small PHP + MySQL site for a 6-month tire storage service: a landing page with
three storage packages, a pickup/delivery date selector split across three
geographic service zones with day-of-week enforcement, a booking form, **Square
card payments to confirm each booking**, and a simple admin dashboard to
manage bookings and see payment status.

## Requirements

- PHP 8.0+ with the `pdo_mysql` and `curl` extensions
- MySQL 5.7+ or MariaDB 10.3+
- Any standard Apache/Nginx setup that runs PHP
- A Square account (free to create) for payment processing
- HTTPS on your domain — Square's Web Payments SDK requires a secure page in
  production (sandbox testing works over plain HTTP/localhost)

## File layout

```
index.php            Landing page + booking form with the Square card field built in
book_and_pay.php      JSON endpoint: validates the booking, charges the card via Square,
                      and only saves the booking if the charge succeeds (atomic)
pay.php               Read-only booking confirmation / receipt summary page
includes/square.php   Minimal Square REST client (plain cURL, no Composer needed)
config.php            Database + Square credentials + site settings — EDIT THIS
schema.sql            Database schema + seed data (3 packages, 4 zones) — fresh installs
migration_zones.sql   Run only if bookings still has drop_off_date (no zones yet)
migration_zone_weekdays.sql  Run only if you already had the old Core/Suburbs/Outlying zones
migration_payments.sql       Run only if bookings doesn't yet have amount_due_cents/payment_status
migration_sets.sql           Run only if bookings doesn't yet have a `quantity` column
migration_bins.sql           Run only if bookings doesn't yet have a `bin_number` column
migration_zone_d_and_prices.sql  Adds Zone D (South of Hunt Club) and updates package prices — safe to run on any existing install
migration_package_pricing.sql   Adds the optional per-tire monthly rate display field and updates Standard Stow to $96 ($4/mo/tire)
migration_package_config.sql    Adds is_featured/is_active so packages are fully admin-manageable (add/hide/feature/reorder)
migration_users.sql             Adds the users table (admin/logistics logins) — safe to run on any existing install
migration_address.sql           Adds the pickup/delivery address field needed by the warehouse scheduler
includes/db.php       PDO connection helper
includes/auth.php     Shared login/role-check helpers (require_login, require_admin)
css/style.css         All styling
js/main.js            Form logic: package/zone estimate, pickup-day enforcement, Square
                      card tokenization, and the book+pay submission
admin/login.php       Login (checks the `users` table)
admin/dashboard.php   View bookings, payment status, update status, assign bins, print labels — admin & logistics
admin/zones.php       Manage zones: name, area description, surcharge, and pickup days — admin only
admin/packages.php    Manage packages: add, edit, feature, hide, reorder, delete, and set pricing — admin only
admin/users.php       Manage user accounts: add, edit role/status, reset passwords, delete — admin only
admin/account.php     Any logged-in user can view their info and change their own password
admin/schedule.php    Warehouse scheduler: pickups & deliveries due on a chosen day, with addresses — admin & logistics
admin/labels.php      Printable sheet: one booking's QR code, repeated 4x on a single page — admin & logistics
admin/logout.php      Log out
```

## Setup

1. **Create the database and import the schema**

   ```bash
   mysql -u root -p -e "CREATE DATABASE tire_storage CHARACTER SET utf8mb4;"
   mysql -u root -p tire_storage < schema.sql
   ```

   This also seeds the three packages (Standard Stow, Full Set + Wash, Fleet Bay,
   each priced per set — customers can book 1 to 3 sets)
   and four geographic pickup zones:

   | Zone | Area | Pickup day(s) |
   |---|---|---|
   | Zone A | East of St-Laurent Blvd | Monday |
   | Zone B | West of St-Laurent Blvd, east of Greenbank Rd | Tuesday & Wednesday |
   | Zone C | West of Greenbank Rd | Thursday & Friday |
   | Zone D | South of Hunt Club Rd | Saturday |

   Edit the `INSERT INTO packages` / `INSERT INTO zones` blocks in `schema.sql`
   first if you want different names, prices, boundaries, surcharges, or
   pickup days — or just edit the rows directly in the `packages` / `zones`
   tables later. `weekday_csv` uses ISO weekday numbers (Mon=1 … Sun=7,
   comma-separated for multiple days) and is what actually enforces which
   dates are selectable — `service_days` is just the human-readable label
   shown on the page.

   **Already running an earlier version of this site?**
   - If you deployed the version *before* zones existed at all (bookings only
     had `drop_off_date`), run `migration_zones.sql` once.
   - If you already ran that migration and had the old
     Core/Suburbs/Outlying zones, run `migration_zone_weekdays.sql` instead —
     it renames those zones to the St-Laurent/Greenbank boundaries above and
     adds the weekday enforcement column. It also prints a list of any
     existing bookings whose pickup date no longer matches their zone's new
     day(s), so you can follow up with those customers manually.
   - Don't run more than one of `schema.sql` / `migration_zones.sql` /
     `migration_zone_weekdays.sql` against the same database.
   - If your `bookings` table doesn't yet have a `quantity` column (multi-set
     bookings), run `migration_sets.sql` too. It also normalizes Fleet Bay's
     price to the same "per set" model as the other two packages — see the
     Notes section below for what that changes.
   - If your `bookings` table doesn't yet have a `bin_number` column
     (physical storage location tracking), run `migration_bins.sql` too.
   - Already have Zones A-C and the old prices seeded? Run
     `migration_zone_d_and_prices.sql` — it adds Zone D if it's not already
     there and updates the three package prices to $138 / $152 / $174 per
     set. It's safe to run more than once (it skips re-adding Zone D if it
     already exists).
   - If your `packages` table doesn't yet have a `monthly_rate_cents` column,
     run `migration_package_pricing.sql` too — it adds that column and
     updates Standard Stow to $96/set ($4/mo per tire × 4 tires × 6 months).
   - If your `packages` table doesn't yet have `is_featured`/`is_active`
     columns, run `migration_package_config.sql` too — it adds them and
     carries over today's "MOST BOOKED" badge (Full Set + Wash) as the
     featured flag, so nothing changes visually until you pick a different
     one in `/admin/packages.php`.
   - If you don't yet have a `users` table (admin logins were still hardcoded
     in `config.php`), run `migration_users.sql` too — it creates the table
     and seeds the same default login you've always had (`admin` /
     `changeme123`), so nothing breaks immediately after migrating. Change
     that password right after (see step 4 below).
   - If your `bookings` table doesn't yet have an `address` column, run
     `migration_address.sql` too — needed for the warehouse scheduler
     (`/admin/schedule.php`) to know where to send pickup/delivery staff.
     Bookings made before this migration will show "No address on file" on
     the scheduler until you follow up with those customers.

2. **Create a MySQL user for the app** (don't use root):

   ```sql
   CREATE USER 'tire_storage_user'@'localhost' IDENTIFIED BY 'a-strong-password';
   GRANT SELECT, INSERT, UPDATE, DELETE ON tire_storage.* TO 'tire_storage_user'@'localhost';
   FLUSH PRIVILEGES;
   ```

   (`DELETE` is needed now too — admin can delete packages and users that
   have no bookings/history attached to them.)

3. **Edit `config.php`** with your real DB host/name/user/password, business
   name, contact info, and address.

4. **Change the default admin password.** `schema.sql` / `migration_users.sql`
   both seed one login: username `admin`, password `changeme123`. Log in at
   `/admin/login.php` with that, then go to **My account** and set a real
   password immediately — or add a brand new admin user from **Manage
   users** and deactivate/delete the default one once you've confirmed the
   new one works. There's always at least one active admin required; the
   app won't let you deactivate, demote, or delete the last one.

5. **Set up Square:**

   1. Create a free account at [squareup.com](https://squareup.com) if you
      don't have one, then go to the
      [Developer Dashboard](https://developer.squareup.com/apps) and create
      an application.
   2. Your new application starts with a **Sandbox** tab and a **Production**
      tab. While testing, copy the *Sandbox* values into `config.php`:
      - `Sandbox Access Token` → `SQUARE_ACCESS_TOKEN`
      - `Sandbox Application ID` → `SQUARE_APPLICATION_ID`
      - Go to the Sandbox test account's **Locations** tab, copy a Location ID → `SQUARE_LOCATION_ID`
      - Leave `SQUARE_ENVIRONMENT` as `'sandbox'`
   3. Check [developer.squareup.com/reference/square](https://developer.squareup.com/reference/square)
      for the current dated API version and update `SQUARE_API_VERSION` in
      `config.php` if it's changed since this was written.
   4. **Test a booking end to end** by filling out the form on your homepage
      and paying with a Square test card — in Sandbox mode no real charge happens:
      | Card | Number | CVV | Expiry / postal |
      |---|---|---|---|
      | Visa | `4111 1111 1111 1111` | `111` | any future date / any postal code |
      You should see the payment appear in your Square Sandbox dashboard, a
      new row in `bookings` with `payment_status = paid` and
      `status = confirmed`, and a matching row in the `payments` table.

      **Note on "ZIP" vs "Postal Code":** Square's card field automatically
      labels this field based on the *card's issuing country*, not your
      business's location — a Canadian-issued card shows "Postal Code," a
      US-issued one shows "ZIP." The standard Visa test card above
      (`4111 1111 1111 1111`) is a US test number, so you'll see "ZIP" while
      testing — that's expected. Real customers paying with Canadian cards
      will automatically see "Postal Code" with no extra configuration
      needed. This label isn't something either Square or this code can force
      to always say one or the other in the combined card field; it's tied to
      the card itself.
   5. **Go live**: switch to the **Production** tab in the Developer
      Dashboard, copy the Production Access Token / Application ID / Location
      ID into `config.php`, and change `SQUARE_ENVIRONMENT` to `'production'`.
      Production card payments require your site to be served over **HTTPS**.

6. **Upload everything** to your web root (e.g. `/var/www/drydock-tires/`)
   and point your Apache/Nginx vhost at that folder, with PHP handling `.php`
   files as usual.

7. Visit your domain — you should see the landing page. Visit `/admin/login.php`
   to log in and see bookings and payment status as they come in.

## Notes

- **Warehouse scheduler** (`/admin/schedule.php`, linked from the dashboard
  as "Schedule", open to both admin and logistics): shows two lists for a
  chosen day — **pickups due** and **deliveries due** — each with the
  customer's name, phone (tap-to-call on mobile), address, package/quantity,
  and vehicle info. Deliveries also show the bin location so staff know
  which bin to pull from. Use the prev/next day arrows or the date picker to
  jump to a specific day; there's also a "Print this day" button for a paper
  copy on the warehouse floor.
  - A booking only appears on **pickups due** while its status is
    `confirmed`, and only appears on **deliveries due** while its status is
    `dropped_off` — so as staff update a booking's status on the dashboard
    (confirmed → dropped_off → picked_up), it naturally moves off one list
    and, if relevant, onto the other. A booking stuck in the wrong status
    won't show up where you'd expect it — that's a cue to fix the status on
    the dashboard, not a scheduler bug.
  - This depends on the new `address` field (see `migration_address.sql`
    above) — a booking made before that migration ran will show "No address
    on file" instead of blocking the page.
- **User accounts and roles.** Admin/logistics logins live in the `users`
  database table (`includes/auth.php` has the two guard functions every
  admin page uses: `require_login()` for anything either role can see,
  `require_admin()` for admin-only pages).
  - **Admin** can do everything: view/manage bookings, assign bins, update
    status, print labels, *and* manage zones, packages, and other user
    accounts (`/admin/zones.php`, `/admin/packages.php`, `/admin/users.php`).
  - **Logistics** can view bookings, assign bins, update status, and print
    labels (`/admin/dashboard.php`, `/admin/labels.php`) — the day-to-day
    physical operations — but can't touch zones, pricing, or user accounts.
  - Every logged-in user (either role) can change their own password from
    **My account** (`/admin/account.php`) without needing an admin to do it
    for them.
  - There must always be at least one active admin — the app blocks
    deactivating, demoting, or deleting the last one, whether you try it on
    yourself or someone else.
  - Passwords are hashed with PHP's `password_hash()` (bcrypt) and never
    stored or logged in plain text. The login page runs `password_verify()`
    against a dummy hash even for a username that doesn't exist, so a failed
    login takes about the same time either way and doesn't leak which
    usernames are registered via a timing difference.
- **Bin assignment**: each booking can be tagged with a physical
  storage bin — container letter, then L or R for the side, then a 2-digit
  slot number (e.g. `AL01` = Container A, Left side, slot 01). This is set
  from `/admin/dashboard.php` (admin or logistics), not by the customer. The
  format is validated both in the browser (the input's `pattern` attribute)
  and on the server (`dashboard.php` rejects anything that doesn't match
  `^[A-Z][LR][0-9]{2}$`), and the database's own CHECK constraint backs that
  up on MySQL 8.0.16+ / MariaDB 10.2+. Assigning a bin already in use by
  another booking that hasn't been picked up yet is blocked with an error
  naming the conflicting booking, so two customers' tires can't end up
  logged in the same bin at the same time. Leaving the field blank and
  saving clears the bin. If you ever add a second container, just start
  using its letter (e.g. `BL01`, `BR01`) — there's nothing to configure.
- **QR labels**: every booking has a "Print labels" link on the
  dashboard, opening `/admin/labels.php?booking=ID` — a printable US Letter
  sheet with the **same QR code printed 4 times** in a 2x2 grid (so you can
  cut them apart and tag each tire, or use spares as needed). Each label also
  shows the customer's name, return date, and bin location as plain text
  underneath, for a quick visual check without scanning anything.
  - The QR code encodes plain text directly — business name, booking number,
    customer name, return date, and bin location — so **any generic QR
    scanner** (a phone camera, a warehouse scanner) can read it and show
    those details immediately, with no lookup system, database, or internet
    connection required at scan time. Edit the `$qrText` block in
    `admin/labels.php` if you want to change what's encoded.
  - If a bin hasn't been assigned yet, both the label text and the QR content
    show "Not assigned yet" rather than a blank field.
  - Click "Print this sheet" to open the browser's print dialog — it's
    designed for a standard 8.5x11 sheet with 0.4in margins.
  - This page loads the [QRCode.js](https://github.com/davidshimjs/qrcodejs)
    library from a CDN (`cdnjs.cloudflare.com`) to draw the QR code in the
    browser — no PHP image libraries or Composer packages needed. It requires
    the admin's browser to have internet access when printing (the rest of
    the site works fine without it).
  - Access requires being logged in (`require_login()`, either role — this
    isn't restricted to admin), so there's no customer-facing link to this
    page.
- **Payment is collected online at the moment of booking, in one step.** The
  card field lives on the same page as the booking form. When the customer
  clicks "Pay & reserve my bay," the card is tokenized by Square in the
  browser (the number never touches your server), then `book_and_pay.php`
  validates everything, charges that token via Square's Payments API, and
  **only saves the booking to the database if the charge succeeds** — inside
  a single database transaction that's rolled back on a decline. There is no
  "unpaid" booking left behind if a card fails; the customer just sees the
  decline reason and can try another card.
- **The one edge case worth knowing about**: if Square successfully charges
  the card but the server then fails to save the booking afterwards (e.g. a
  database hiccup at exactly the wrong moment — rare), the customer is shown
  a message asking them to contact you with the Square payment reference,
  and the server logs a `CRITICAL` line with that reference and the
  customer's name/email so you can create the booking manually and never
  lose track of the payment. Keep an eye on your PHP error log for lines
  starting with `CRITICAL: Square payment`.
- Each successful booking gets a random `access_token`, giving it a
  private confirmation link (`pay.php?booking=ID&token=...`) the customer can
  bookmark or you can resend — shown after booking and from the admin
  dashboard's "view booking" link.
- The booking form does basic server-side validation (required fields, valid
  email, pickup date not in the past, quantity between 1 and 3 sets) and uses
  prepared statements everywhere, so it's safe against SQL injection out of
  the box.
- **Sets and pricing**: customers choose 1, 2, or 3 sets of tires (up to 4
  tires each) on any package. The total is simply `package price × number of
  sets`, plus the zone surcharge once (a single pickup/delivery visit covers
  however many sets are in the booking). **This changed Fleet Bay's pricing
  model**: it used to bundle 8 tires (two sets) into one flat $199, but is now
  priced the same way as the other two packages — $199 *per set*, so booking
  2 sets of Fleet Bay now costs $398 rather than the old flat $199. Adjust
  `price_cents` in the `packages` table if that per-set rate isn't what you
  want for Fleet Bay going forward.
- **Standard Stow is advertised as "$4/mo per tire"** rather than a flat
  total. The card shows that rate as the headline price, with "$96 total for
  6 months, up to 4 tires" underneath — $96 being 4 tires × 6 months × $4,
  charged as a flat amount regardless of the exact pickup/delivery dates the
  customer ends up choosing (it does not recalculate if they adjust the
  delivery date within the 4–8 month window). `price_cents` (what's actually
  charged) and `monthly_rate_cents` (the advertised per-tire rate, display
  only) are two independent numbers in the database — nothing recalculates
  one from the other automatically, so if you change one on
  `/admin/packages.php` you'll want to update the other to match, or they'll
  say different things. Leave `monthly_rate_cents` blank on a package to show
  a plain flat total instead, like Full Set + Wash and Fleet Bay do.
- **Packages are fully manageable from the admin portal — no SQL required.**
  `/admin/packages.php` (linked from the dashboard and the zones page as
  "Manage packages") lets you:
  - **Edit** every field on an existing package — name, tagline, feature
    list, tires per set, total price, and optional advertised monthly rate.
  - **Add** a brand new package. A URL-safe slug is generated from the name
    automatically (with a numeric suffix if it would collide with an
    existing one), and "Display order" defaults to the end of the list if
    left at 0.
  - **Feature** one package with the "MOST BOOKED" badge — checking this box
    on any package automatically unchecks it on every other package, so
    exactly one (or none) is ever featured. This replaced an earlier version
    that hardcoded the badge to a specific package's slug in `index.php`;
    it's now driven entirely by the `is_featured` column.
  - **Hide** a package from the homepage by unchecking "Visible on site"
    (`is_active`). A hidden package is also rejected server-side if someone
    tries to book it directly (`book_and_pay.php` requires `is_active = 1`),
    so an old bookmarked link can't route around it. This is the safe way to
    retire a package that already has bookings against it.
  - **Delete** a package outright — but only if zero bookings reference it.
    If any do, deletion is refused with a message telling you to hide it
    instead, since deleting it would either break the foreign key or orphan
    those bookings' package info.
  - **Reorder** packages via the "Display order" number on each one; the
    homepage and dropdown both sort by this ascending.
  Changes apply immediately to new bookings; past bookings keep whatever
  `amount_due_cents` was snapshotted at the time they were made, regardless
  of later price changes.
- **Delivery date defaults to 6 months after pickup, but stays editable.**
  When the customer picks a pickup date, the delivery field auto-fills to
  exactly 6 calendar months later (e.g. March 14 pickup → September 14
  delivery; a pickup on the 31st correctly lands on the last day of a shorter
  target month) using `add_calendar_months()` in `js/main.js`, then they can
  adjust it if they want. The server re-validates independently with
  `add_calendar_months()` in `book_and_pay.php` and requires the submitted
  delivery date to fall between 4 and 8 months after pickup — close enough to
  6 months to stay a coherent storage term, without hard-locking the exact
  date.
- **Zones**: each customer picks one of four geographic areas — three split
  on St-Laurent Blvd and Greenbank Rd, plus a fourth for south of Hunt Club
  Rd — which fixes their pickup day(s) and an optional distance surcharge
  (shown live in a price estimate on the form).
- **Pickup date is a custom calendar, not the browser's native date picker.**
  Clicking the "Pickup date" field opens a month calendar built in
  `js/main.js` (`renderCalendar()`); days that aren't a pickup day for the
  selected zone are greyed out and genuinely unclickable (`disabled` on the
  button), not just auto-corrected after the fact. Switching zones after a
  date is already chosen clears it if it no longer applies, with a message
  explaining why, rather than silently moving it to a different date the
  customer didn't pick. `book_and_pay.php` independently re-checks the
  weekday server-side before saving regardless — so the rule holds even if
  JavaScript is disabled or the request is forged directly.
- **Manage zones from the admin portal — no SQL required.** `/admin/zones.php`
  (linked from the dashboard as "Manage zones") lets you edit each zone's
  name, customer-facing area description, dollar surcharge, and which
  weekdays it picks up on — check/uncheck days and save. It also validates
  that at least one day is selected and generates the human-readable label
  (e.g. "Tuesday & Wednesday") from whichever days you check, so
  `weekday_csv` and `service_days` never drift out of sync. You can add
  further zones from the same page; changes take effect immediately for new
  bookings and never touch bookings already made.
- The hero image is drawn with CSS/SVG shapes (no external image files to
  manage or license) — a stack of tires under a work light inside a
  corrugated container. Swap in a real photo later by replacing the `.rig`
  block in `index.php` with an `<img>` tag if you'd prefer.
- To add more packages, just insert more rows into `packages` — the landing
  page and booking form both pull the list from the database automatically.

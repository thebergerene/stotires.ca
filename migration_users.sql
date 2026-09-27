-- Migration: moves admin login from hardcoded config.php constants into a
-- proper `users` table, with two roles: admin and logistics.
--
--   mysql -u root -p tire_storage < migration_users.sql
--
-- After running this, ADMIN_USERNAME / ADMIN_PASSWORD_HASH in config.php are
-- no longer read by the code — you can delete them from config.php or just
-- leave them there unused, your choice.

CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name     VARCHAR(120) NOT NULL,
    role          ENUM('admin', 'logistics') NOT NULL DEFAULT 'logistics',
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seeds the same default login you've had all along (username "admin",
-- password "changeme123"), so nothing breaks immediately after migrating.
-- If you already changed ADMIN_PASSWORD_HASH in config.php to something
-- else, that old password will NOT carry over automatically — update the
-- password_hash below yourself (or just log in with changeme123 and change
-- it from Admin > My account right after).
INSERT INTO users (username, password_hash, full_name, role)
SELECT 'admin', '$2b$10$kUvT1xgXyaRRX/kufB3cCe5mTZa4d73WBRHvAzwHY.vwu46Fp8NE.', 'Site Admin', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');

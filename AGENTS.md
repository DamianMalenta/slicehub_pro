# AGENTS.md

## Cursor Cloud specific instructions

### Overview

SliceHub Enterprise OS is a multi-tenant restaurant management system built on a pure LAMP stack:
- **Backend:** PHP 8.3 (no framework, no Composer)
- **Frontend:** Vanilla JS + Tailwind CSS (CDN) — no Node.js, no build step
- **Database:** MariaDB 10.11 (`slicehub_pro_v2`, charset `utf8mb4_unicode_ci`)
- **Web server:** Apache 2 with mod_rewrite

There are **zero external dependencies to install** (no `package.json`, no `composer.json`). The update script handles system packages only.

### Starting services

After the update script runs, start services manually each session:

```bash
# Start MariaDB
mkdir -p /run/mysqld && chown mysql:mysql /run/mysqld && mysqld_safe &
sleep 2

# Start Apache
apachectl start
```

The app is served at `http://localhost/slicehub/` via symlink `/var/www/html/slicehub -> /workspace`.

### Lint

```bash
find /workspace -name "*.php" -not -path "*/vendor/*" | xargs -P4 -I{} php -l {}
```

All output should say "No syntax errors detected". Any other output is a failure.

### Tests

Open `http://localhost/slicehub/tests/test_runner.html` in a browser and click "Uruchom Wszystkie Testy". All 61 tests should pass. The tests are JavaScript-based and call the REST API endpoints.

### API authentication

- **Kiosk login (PIN):** `POST /slicehub/api/auth/login.php` with `{"mode":"kiosk","tenant_id":1,"pin_code":"0000"}`
- **System login:** `POST /slicehub/api/auth/login.php` with `{"mode":"system","username":"admin","password":"password"}`
- Returns JWT token in `data.token`. Pass as `Authorization: Bearer <token>` header.

### Demo accounts (tenant_id=1)

| Username | Role    | PIN  |
|----------|---------|------|
| manager  | manager | 0000 |
| waiter1  | waiter  | 1111 |
| waiter2  | waiter  | 2222 |
| cook1    | cook    | 3333 |
| driver1  | driver  | 4444 |
| driver2  | driver  | 5555 |
| team1    | team    | 6666 |

### Key gotchas

1. **No npm/Node.js/build tools.** The `.cursorrules` explicitly forbids them. Tailwind is loaded from CDN.
2. **Database config** is hardcoded in `core/db_config.php` (root@localhost, empty password, database `slicehub_pro_v2`).
3. **MariaDB root auth** must use `mysql_native_password` with empty password (not unix_socket) for Apache's PHP process to connect.
4. **Migration failures for 015/030/037** are pre-existing MariaDB 10.11 compatibility issues and do not block the application from running.
5. **Internal URLs** assume the app is at path `/slicehub/`. The symlink `/var/www/html/slicehub -> /workspace` provides this.
6. **Database reset path:** `nuclear_reset.php` → `seed_demo_all.php` to get clean demo data.
7. **Full schema rebuild:** Import `001_init_slicehub_pro_v2.sql` → `php scripts/apply_migrations_chain.php` → `php scripts/setup_database.php` → `php scripts/seed_demo_all.php`

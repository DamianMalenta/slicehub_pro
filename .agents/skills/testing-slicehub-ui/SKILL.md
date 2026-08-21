---
name: testing-slicehub-ui
description: How to run and UI-test SliceHub Pro locally (Apache/MariaDB golden path, headless test runner, hub/KDS login and order-edit flows)
---

# Testing SliceHub Pro UI

## Services
- MariaDB: `sudo mkdir -p /run/mysqld && sudo chown mysql:mysql /run/mysqld && sudo mysqld_safe &` (root, empty password, DB `slicehub_pro_v2`).
- Apache serves the app at `http://localhost/slicehub/` via symlink `/var/www/html/slicehub -> repo`. If missing: `sudo ln -s <repo> /var/www/html/slicehub && sudo apachectl start`.

## Golden path DB rebuild (if tests fail unexpectedly, reseed first)
```bash
mysql -uroot -e "DROP DATABASE IF EXISTS slicehub_pro_v2; CREATE DATABASE slicehub_pro_v2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql -uroot slicehub_pro_v2 < database/migrations/001_init_slicehub_pro_v2.sql   # NOTE: file is under database/migrations/, not database/
php scripts/apply_migrations_chain.php
php scripts/seed_demo_all.php
```

## Headless test runner
```bash
CHROME_PATH=$HOME/.local/bin/google-chrome node scripts/run_test_runner_headless.cjs
```
- Default Chrome path `/usr/local/bin/google-chrome` may not exist; set `CHROME_PATH` (this box: `~/.local/bin/google-chrome`).
- AGENTS.md says expected 62 pass / 0 fail, but T24 (studio_recipe.js → api_menu_studio.php missing) may show as a pre-existing WARN → 61 pass / 0 fail / 1 warn. Reseed the DB if there are actual failures.

## UI logins (demo seed, tenant 1)
- Hub `modules/hub/`: system login `manager` / `password`. Auth token stored in localStorage carries over to KDS/other modules in the same browser.
- KDS `modules/kds/`: token from localStorage, or PIN `3333` (cook). Kiosk PIN fallback `0000`.
- KDS board appears to show only active (new/preparing) tickets; orders in `ready` may not display, so edit a `new`/`preparing` order if you need to see the KDS delta block.

## Order-edit feature checks
- Hub dashboard card "Edycja zamówień" opens the Dark Glass modal (overlay `hoe-overlay`, list → edit view with qty ±, trash, modifier select→chips, comment input, dish select + Dodaj, `hoe-btn-save`).
- Successful save → JS alert `Zapisano. Zmiany: +N / −N / ~N. KDS zobaczy różnice na bilecie.` and `sh_orders.kitchen_delta` JSON populated.
- Legacy demo orders (e.g. #0016) contain SKU `MARGHERITA` not in menu → save shows inline error `Line #N: SKU 'MARGHERITA' not found for this tenant.` (modal stays open) — useful for error-handling tests.
- DB cross-check: `mysql -uroot slicehub_pro_v2 -e "SELECT order_number,status,kitchen_delta FROM sh_orders WHERE order_number='S1'\G"`.

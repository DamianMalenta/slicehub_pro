-- SliceHub Pro V2 — exactly 3 driver accounts + clean sh_drivers / fleet rows.
-- Keeps usernames: driver_marek, driver_kasia, driver_tomek (PINs 1111 / 2222 / 3333).
-- Removes other driver-role users and removes non-drivers from sh_drivers.

USE slicehub_pro_v2;

DELETE d FROM sh_driver_locations d
WHERE d.tenant_id = 1
  AND d.driver_id NOT IN (
    SELECT u.id FROM sh_users u
    WHERE u.tenant_id = 1
      AND u.username IN ('driver_marek', 'driver_kasia', 'driver_tomek')
  );

DELETE s FROM sh_driver_shifts s
WHERE s.tenant_id = 1
  AND s.driver_id NOT IN (
    SELECT CAST(u.id AS CHAR) FROM sh_users u
    WHERE u.tenant_id = 1
      AND u.username IN ('driver_marek', 'driver_kasia', 'driver_tomek')
  );

DELETE dr FROM sh_drivers dr
WHERE dr.tenant_id = 1
  AND dr.user_id NOT IN (
    SELECT u.id FROM sh_users u
    WHERE u.tenant_id = 1
      AND u.username IN ('driver_marek', 'driver_kasia', 'driver_tomek')
  );

DELETE FROM sh_users
WHERE tenant_id = 1
  AND role = 'driver'
  AND username NOT IN ('driver_marek', 'driver_kasia', 'driver_tomek');

INSERT IGNORE INTO sh_drivers (user_id, tenant_id, status)
SELECT u.id, 1, 'available'
FROM sh_users u
WHERE u.tenant_id = 1
  AND u.username IN ('driver_marek', 'driver_kasia', 'driver_tomek');

UPDATE sh_drivers dr
JOIN sh_users u ON u.id = dr.user_id AND u.tenant_id = dr.tenant_id
SET dr.status = 'available'
WHERE dr.tenant_id = 1
  AND u.username IN ('driver_marek', 'driver_kasia', 'driver_tomek');

UPDATE sh_users SET pin_code = '1111' WHERE tenant_id = 1 AND username = 'driver_marek';
UPDATE sh_users SET pin_code = '2222' WHERE tenant_id = 1 AND username = 'driver_kasia';
UPDATE sh_users SET pin_code = '3333' WHERE tenant_id = 1 AND username = 'driver_tomek';

UPDATE sh_users SET username = 'kucharz_piotr', pin_code = '8888' WHERE tenant_id = 1 AND id = 5 AND role = 'cook';
UPDATE sh_users SET username = 'kelnerka_ola' WHERE tenant_id = 1 AND id = 4 AND username = 'kierowca_tomek';

-- PIN login uses LIMIT 1 on pin_code: must be unique per tenant across all roles.
UPDATE sh_users SET pin_code = '5111' WHERE tenant_id = 1 AND username = 'kelner_piotr';
UPDATE sh_users SET pin_code = '5222' WHERE tenant_id = 1 AND username = 'kelnerka_ola';

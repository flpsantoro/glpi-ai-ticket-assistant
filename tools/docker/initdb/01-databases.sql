-- One database per GLPI version of the development environment
CREATE DATABASE IF NOT EXISTS glpi11 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS glpi12 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON glpi11.* TO 'glpi'@'%';
GRANT ALL PRIVILEGES ON glpi12.* TO 'glpi'@'%';
-- GLPI checks the access to the time zones table
GRANT SELECT ON mysql.time_zone_name TO 'glpi'@'%';
FLUSH PRIVILEGES;

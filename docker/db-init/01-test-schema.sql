-- The suite (tests/Support/TestDatabase.php) drops every table in its schema
-- between tests, so it must never be the application's own database. Give it a
-- dedicated schema here; DB_TEST_DATABASE points at it in docker-compose.yml.
CREATE DATABASE IF NOT EXISTS codevault_test
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON codevault_test.* TO 'codevault'@'%';
FLUSH PRIVILEGES;

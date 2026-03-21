-- Setup Read-Only Database User for SELECT Operations
-- Description: Creates a read-only user for safe SELECT queries
-- Date: 2026-03-21
-- Usage: mysql -u root -p < setup_readonly_db_user.sql

-- Create read-only user (change password!)
CREATE USER IF NOT EXISTS 'proxbet_readonly'@'localhost' 
IDENTIFIED BY 'CHANGE_THIS_PASSWORD_IN_PRODUCTION';

-- Grant SELECT privileges only
GRANT SELECT ON proxbet.* TO 'proxbet_readonly'@'localhost';

-- Grant specific privileges for read operations
GRANT SHOW VIEW ON proxbet.* TO 'proxbet_readonly'@'localhost';

-- Apply changes
FLUSH PRIVILEGES;

-- Verify user privileges
SHOW GRANTS FOR 'proxbet_readonly'@'localhost';

-- Add to .env file:
-- DB_READONLY_HOST=localhost
-- DB_READONLY_USER=proxbet_readonly
-- DB_READONLY_PASSWORD=your_secure_password_here
-- DB_READONLY_NAME=proxbet

-- Usage in PHP:
-- For read-only operations, use:
-- $pdoReadOnly = new PDO(
--     "mysql:host={$_ENV['DB_READONLY_HOST']};dbname={$_ENV['DB_READONLY_NAME']};charset=utf8mb4",
--     $_ENV['DB_READONLY_USER'],
--     $_ENV['DB_READONLY_PASSWORD'],
--     [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
-- );

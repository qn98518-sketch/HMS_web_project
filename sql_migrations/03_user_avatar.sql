-- Add avatar storage without changing existing account data. Safe to rerun.
SET @avatar_ddl = (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE users ADD COLUMN avatar VARCHAR(255) NULL',
        'SELECT ''users.avatar already exists'' AS migration_status')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar'
);
PREPARE avatar_migration FROM @avatar_ddl;
EXECUTE avatar_migration;
DEALLOCATE PREPARE avatar_migration;

<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Primary Backup Storage Directory
    |--------------------------------------------------------------------------
    |
    | The local directory where `temupulang:backup` writes verified zip
    | archives (`temu_pulang_YYYYmmdd_HHMMSS.zip`).
    |
    */

    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    /*
    |--------------------------------------------------------------------------
    | Secondary Backup Copy Destination (Optional)
    |--------------------------------------------------------------------------
    |
    | An optional second folder on a separate physical drive, external USB
    | disk, or synced folder where each completed backup zip is copied.
    |
    */

    'copy_path' => env('BACKUP_COPY_PATH', ''),

    /*
    |--------------------------------------------------------------------------
    | Backup Retention Period (Days)
    |--------------------------------------------------------------------------
    |
    | Backup files matching `temu_pulang_*.zip` older than this number of days
    | are pruned automatically, except that the newest 3 backups are always
    | preserved regardless of age.
    |
    */

    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 14),

    /*
    |--------------------------------------------------------------------------
    | MySQL / MariaDB Binary Paths
    |--------------------------------------------------------------------------
    |
    | Full path or binary name for `mysqldump` and `mysql` client executables.
    | On Windows XAMPP, typically `C:\xampp\mysql\bin\mysqldump.exe` and
    | `C:\xampp\mysql\bin\mysql.exe`.
    |
    */

    'mysqldump_path' => env('MYSQLDUMP_PATH', 'mysqldump'),

    'mysql_client_path' => env('MYSQL_CLIENT_PATH', 'mysql'),

    /*
    |--------------------------------------------------------------------------
    | Maximum Allowed Backup Age (Hours)
    |--------------------------------------------------------------------------
    |
    | Used by `temupulang:preflight` and the Owner Dashboard backup status
    | banner to flag overdue nightly backups (default 26 hours).
    |
    */

    'max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),

    /*
    |--------------------------------------------------------------------------
    | Require Completed Backup in Production Preflight
    |--------------------------------------------------------------------------
    |
    | When true and APP_ENV=production, `temupulang:preflight` reports [FAIL]
    | if no `backup.completed` ActivityLog record exists yet.
    |
    */

    'required' => (bool) env('BACKUP_REQUIRED', false),

    /*
    |--------------------------------------------------------------------------
    | Minimum Verified SQL Dump Size (Bytes) & Process Timeout (Seconds)
    |--------------------------------------------------------------------------
    */

    'min_dump_bytes' => (int) env('BACKUP_MIN_DUMP_BYTES', 1024),

    'timeout_seconds' => (int) env('BACKUP_TIMEOUT_SECONDS', 300),

];

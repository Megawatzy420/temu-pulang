<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\ShopSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;
use ZipArchive;

class BackupService
{
    public const MIN_RETAINED_BACKUPS = 3;

    public const RESTORE_CHECK_DB_PREFIX = 'temu_restore_check_';

    /**
     * Execute a database and QRIS asset backup, package into a timestamped zip archive,
     * optionally copy to a secondary destination, enforce retention, and log to ActivityLog.
     *
     * @return array{
     *     success: bool,
     *     file_name: ?string,
     *     zip_path: ?string,
     *     size_bytes: int,
     *     duration_seconds: int,
     *     copy_status: string,
     *     copy_error: ?string,
     *     deleted_primary: int,
     *     deleted_copy: int,
     *     error: ?string
     * }
     */
    public function runBackup(bool $noCopy = false, ?CarbonInterface $now = null): array
    {
        $startedAt = hrtime(true);
        $defaultConnection = (string) config('database.default', '');

        if ($defaultConnection !== 'mysql') {
            $message = "Database backup requires the default database connection to be 'mysql' (current: '{$defaultConnection}').";
            $this->recordActivity('backup.failed', $message, [
                'connection' => $defaultConnection,
                'duration_seconds' => 0,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'file_name' => null,
                'zip_path' => null,
                'size_bytes' => 0,
                'duration_seconds' => 0,
                'copy_status' => 'skipped',
                'copy_error' => null,
                'deleted_primary' => 0,
                'deleted_copy' => 0,
                'error' => $message,
            ];
        }

        $mysqlConfig = (array) config('database.connections.mysql', []);
        $host = (string) ($mysqlConfig['host'] ?? '127.0.0.1');
        $port = (string) ($mysqlConfig['port'] ?? '3306');
        $database = (string) ($mysqlConfig['database'] ?? '');
        $username = (string) ($mysqlConfig['username'] ?? 'root');
        $secret = (string) ($mysqlConfig['password'] ?? '');

        $backupDir = rtrim((string) config('backup.path', storage_path('app/backups')), '\\/');
        $copyDir = trim((string) config('backup.copy_path', ''));
        $retentionDays = max(1, (int) config('backup.retention_days', 14));
        $mysqldumpBin = (string) config('backup.mysqldump_path', 'mysqldump');
        $minDumpBytes = max(1, (int) config('backup.min_dump_bytes', 1024));
        $timeoutSeconds = max(30, (int) config('backup.timeout_seconds', 300));

        $timezone = $this->resolveShopTimezone();
        $referenceNow = ($now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now())
            ->setTimezone($timezone);

        $timestampSlug = $referenceNow->format('Ymd_His');
        $zipFileName = "temu_pulang_{$timestampSlug}.zip";
        $tempSqlPath = $backupDir.DIRECTORY_SEPARATOR."temp_dump_{$timestampSlug}_".bin2hex(random_bytes(4)).'.sql';
        $zipFilePath = $backupDir.DIRECTORY_SEPARATOR.$zipFileName;

        try {
            $this->ensureDirectoryExists($backupDir);

            $command = $this->buildMysqldumpCommand(
                binary: $mysqldumpBin,
                host: $host,
                port: $port,
                username: $username,
                database: $database,
                resultFile: $tempSqlPath,
            );

            $result = Process::timeout($timeoutSeconds)
                ->env(['MYSQL_PWD' => $secret])
                ->run($command);

            if (! $result->successful()) {
                $rawErr = trim($result->errorOutput().' '.$result->output());
                $scrubbedErr = $this->scrubSecret($rawErr, $secret);
                throw new RuntimeException(
                    'mysqldump exited with code '.$result->exitCode().($scrubbedErr !== '' ? ": {$scrubbedErr}" : '.')
                );
            }

            $verificationError = $this->verifySqlDumpFile($tempSqlPath, $minDumpBytes);
            if ($verificationError !== null) {
                throw new RuntimeException($verificationError);
            }

            $dumpSizeBytes = (int) filesize($tempSqlPath);
            $tableCount = $this->countCreateTableStatements($tempSqlPath);

            $manifestText = $this->buildManifest(
                timestamp: $referenceNow,
                database: $database,
                tableCount: $tableCount,
                dumpSizeBytes: $dumpSizeBytes,
            );

            $this->createBackupZipArchive(
                zipFilePath: $zipFilePath,
                tempSqlPath: $tempSqlPath,
                manifestText: $manifestText,
            );

            $this->safeUnlink($tempSqlPath);

            if (! is_file($zipFilePath) || (int) filesize($zipFilePath) <= 0) {
                throw new RuntimeException('Backup zip archive was not created or is empty.');
            }

            $zipSizeBytes = (int) filesize($zipFilePath);
            $durationSeconds = $this->elapsedSeconds($startedAt);

            $copyStatus = 'skipped';
            $copyError = null;

            if (! $noCopy && $copyDir !== '') {
                try {
                    $normalizedCopyDir = rtrim($copyDir, '\\/');
                    $this->ensureDirectoryExists($normalizedCopyDir);
                    $targetCopyPath = $normalizedCopyDir.DIRECTORY_SEPARATOR.$zipFileName;

                    if (! @copy($zipFilePath, $targetCopyPath) || ! is_file($targetCopyPath)) {
                        throw new RuntimeException("Could not copy backup zip to secondary destination '{$normalizedCopyDir}'.");
                    }

                    $copyStatus = 'copied';
                } catch (Throwable $copyEx) {
                    $copyStatus = 'failed';
                    $copyError = $this->scrubSecret($copyEx->getMessage(), $secret);
                    $this->recordActivity(
                        action: 'backup.copy_failed',
                        description: "Secondary backup copy failed for {$zipFileName}: {$copyError}",
                        properties: [
                            'file_name' => $zipFileName,
                            'size_bytes' => $zipSizeBytes,
                            'duration_seconds' => $durationSeconds,
                            'error' => $copyError,
                        ],
                    );
                }
            }

            $deletedPrimary = $this->applyRetention($backupDir, $retentionDays, $referenceNow);
            $deletedCopy = (! $noCopy && $copyDir !== '' && $copyStatus === 'copied')
                ? $this->applyRetention(rtrim($copyDir, '\\/'), $retentionDays, $referenceNow)
                : 0;

            $this->recordActivity(
                action: 'backup.completed',
                description: "Database backup {$zipFileName} completed ({$zipSizeBytes} bytes).",
                properties: [
                    'file_name' => $zipFileName,
                    'size_bytes' => $zipSizeBytes,
                    'dump_size_bytes' => $dumpSizeBytes,
                    'table_count' => $tableCount,
                    'duration_seconds' => $durationSeconds,
                    'copy_status' => $copyStatus,
                    'deleted_primary' => $deletedPrimary,
                    'deleted_copy' => $deletedCopy,
                ],
            );

            return [
                'success' => true,
                'file_name' => $zipFileName,
                'zip_path' => $zipFilePath,
                'size_bytes' => $zipSizeBytes,
                'duration_seconds' => $durationSeconds,
                'copy_status' => $copyStatus,
                'copy_error' => $copyError,
                'deleted_primary' => $deletedPrimary,
                'deleted_copy' => $deletedCopy,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $this->safeUnlink($tempSqlPath);
            $this->safeUnlink($zipFilePath);

            $durationSeconds = $this->elapsedSeconds($startedAt);
            $scrubbedError = $this->scrubSecret($e->getMessage(), $secret);

            $this->recordActivity(
                action: 'backup.failed',
                description: "Database backup failed: {$scrubbedError}",
                properties: [
                    'file_name' => $zipFileName,
                    'size_bytes' => 0,
                    'duration_seconds' => $durationSeconds,
                    'error' => $scrubbedError,
                ],
            );

            return [
                'success' => false,
                'file_name' => null,
                'zip_path' => null,
                'size_bytes' => 0,
                'duration_seconds' => $durationSeconds,
                'copy_status' => 'skipped',
                'copy_error' => null,
                'deleted_primary' => 0,
                'deleted_copy' => 0,
                'error' => $scrubbedError,
            ];
        }
    }

    /**
     * Verify a backup zip archive by extracting database.sql, creating a scratch database
     * named `temu_restore_check_<random>`, importing the dump via the mysql client,
     * comparing table and key row counts against the live database, and always dropping
     * the scratch database and temporary extraction folder in a finally block.
     *
     * @return array{
     *     success: bool,
     *     file_name: string,
     *     temp_database: string,
     *     live_tables_count: int,
     *     restored_tables_count: int,
     *     row_counts: array<string, array{live: int, restored: int, diff: int}>,
     *     duration_seconds: int,
     *     error: ?string
     * }
     */
    public function verifyBackup(?string $fileOption = null, ?string $forcedTempDbName = null): array
    {
        $startedAt = hrtime(true);
        $defaultConnection = (string) config('database.default', '');

        if ($defaultConnection !== 'mysql') {
            $message = "Backup verification requires the default database connection to be 'mysql' (current: '{$defaultConnection}').";
            $this->recordActivity('backup.verify_failed', $message, [
                'connection' => $defaultConnection,
                'duration_seconds' => 0,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'file_name' => (string) ($fileOption ?? ''),
                'temp_database' => '',
                'live_tables_count' => 0,
                'restored_tables_count' => 0,
                'row_counts' => [],
                'duration_seconds' => 0,
                'error' => $message,
            ];
        }

        $mysqlConfig = (array) config('database.connections.mysql', []);
        $host = (string) ($mysqlConfig['host'] ?? '127.0.0.1');
        $port = (string) ($mysqlConfig['port'] ?? '3306');
        $liveDatabase = (string) ($mysqlConfig['database'] ?? '');
        $username = (string) ($mysqlConfig['username'] ?? 'root');
        $secret = (string) ($mysqlConfig['password'] ?? '');

        $backupDir = rtrim((string) config('backup.path', storage_path('app/backups')), '\\/');
        $mysqlBin = (string) config('backup.mysql_client_path', 'mysql');
        $timeoutSeconds = max(30, (int) config('backup.timeout_seconds', 300));

        $tempDbName = $forcedTempDbName ?? $this->generateRestoreCheckDatabaseName();
        $this->assertSafeRestoreCheckDatabaseName($tempDbName, $liveDatabase);

        $zipPath = $this->resolveBackupZipPath($backupDir, $fileOption);
        $fileName = $zipPath !== null ? basename($zipPath) : (string) ($fileOption ?? '');

        if ($zipPath === null || ! is_file($zipPath)) {
            $message = $fileOption !== null && $fileOption !== ''
                ? "Backup file not found: '{$fileName}'."
                : 'No temu_pulang_*.zip backup archive found in the backup directory.';

            $this->recordActivity('backup.verify_failed', $message, [
                'file_name' => $fileName,
                'temp_database' => $tempDbName,
                'duration_seconds' => 0,
                'error' => $message,
            ]);

            return [
                'success' => false,
                'file_name' => $fileName,
                'temp_database' => $tempDbName,
                'live_tables_count' => 0,
                'restored_tables_count' => 0,
                'row_counts' => [],
                'duration_seconds' => 0,
                'error' => $message,
            ];
        }

        $tempExtractDir = $backupDir.DIRECTORY_SEPARATOR.'verify_tmp_'.bin2hex(random_bytes(5));
        $tempDbCreated = false;

        try {
            $this->ensureDirectoryExists($tempExtractDir);

            $zip = new ZipArchive;
            $opened = $zip->open($zipPath);
            if ($opened !== true) {
                throw new RuntimeException("Could not open backup zip archive '{$fileName}'.");
            }

            if ($zip->locateName('database.sql') === false) {
                $zip->close();
                throw new RuntimeException("Backup archive '{$fileName}' does not contain database.sql.");
            }

            if (! $zip->extractTo($tempExtractDir, ['database.sql'])) {
                $zip->close();
                throw new RuntimeException("Failed to extract database.sql from '{$fileName}'.");
            }
            $zip->close();

            $extractedSqlPath = $tempExtractDir.DIRECTORY_SEPARATOR.'database.sql';
            if (! is_file($extractedSqlPath) || (int) filesize($extractedSqlPath) <= 0) {
                throw new RuntimeException('Extracted database.sql is missing or empty.');
            }

            // 1. Create scratch database
            $createCmd = $this->buildMysqlExecuteCommand(
                binary: $mysqlBin,
                host: $host,
                port: $port,
                username: $username,
                sql: "CREATE DATABASE `{$tempDbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;",
            );

            $createResult = Process::timeout($timeoutSeconds)
                ->env(['MYSQL_PWD' => $secret])
                ->run($createCmd);

            if (! $createResult->successful()) {
                $err = $this->scrubSecret(trim($createResult->errorOutput().' '.$createResult->output()), $secret);
                throw new RuntimeException("Failed to create temporary restore database '{$tempDbName}': {$err}");
            }

            $tempDbCreated = true;

            // 2. Import extracted database.sql into scratch database
            $normalizedSqlForSource = str_replace('\\', '/', $extractedSqlPath);
            $importCmd = $this->buildMysqlExecuteCommand(
                binary: $mysqlBin,
                host: $host,
                port: $port,
                username: $username,
                sql: "SOURCE {$normalizedSqlForSource};",
                database: $tempDbName,
            );

            $importResult = Process::timeout($timeoutSeconds)
                ->env(['MYSQL_PWD' => $secret])
                ->run($importCmd);

            if (! $importResult->successful()) {
                $err = $this->scrubSecret(trim($importResult->errorOutput().' '.$importResult->output()), $secret);
                throw new RuntimeException("Failed to import database.sql into '{$tempDbName}': {$err}");
            }

            // 3. Compare table counts and key table row counts
            $stats = $this->queryRestoreComparisonStats(
                binary: $mysqlBin,
                host: $host,
                port: $port,
                username: $username,
                secret: $secret,
                liveDatabase: $liveDatabase,
                restoredDatabase: $tempDbName,
                timeoutSeconds: $timeoutSeconds,
            );

            if ($stats['restored_tables_count'] <= 0) {
                throw new RuntimeException("Restored database '{$tempDbName}' contains 0 tables after import.");
            }

            $durationSeconds = $this->elapsedSeconds($startedAt);

            $this->recordActivity(
                action: 'backup.verified',
                description: "Backup {$fileName} verified successfully in temporary database {$tempDbName}.",
                properties: [
                    'file_name' => $fileName,
                    'temp_database' => $tempDbName,
                    'live_tables_count' => $stats['live_tables_count'],
                    'restored_tables_count' => $stats['restored_tables_count'],
                    'row_counts' => $stats['row_counts'],
                    'duration_seconds' => $durationSeconds,
                ],
            );

            return [
                'success' => true,
                'file_name' => $fileName,
                'temp_database' => $tempDbName,
                'live_tables_count' => $stats['live_tables_count'],
                'restored_tables_count' => $stats['restored_tables_count'],
                'row_counts' => $stats['row_counts'],
                'duration_seconds' => $durationSeconds,
                'error' => null,
            ];
        } catch (Throwable $e) {
            $durationSeconds = $this->elapsedSeconds($startedAt);
            $scrubbedError = $this->scrubSecret($e->getMessage(), $secret);

            $this->recordActivity(
                action: 'backup.verify_failed',
                description: "Backup verification failed for {$fileName}: {$scrubbedError}",
                properties: [
                    'file_name' => $fileName,
                    'temp_database' => $tempDbName,
                    'duration_seconds' => $durationSeconds,
                    'error' => $scrubbedError,
                ],
            );

            return [
                'success' => false,
                'file_name' => $fileName,
                'temp_database' => $tempDbName,
                'live_tables_count' => 0,
                'restored_tables_count' => 0,
                'row_counts' => [],
                'duration_seconds' => $durationSeconds,
                'error' => $scrubbedError,
            ];
        } finally {
            if ($tempDbCreated) {
                $this->dropTemporaryRestoreDatabase(
                    binary: $mysqlBin,
                    host: $host,
                    port: $port,
                    username: $username,
                    secret: $secret,
                    tempDbName: $tempDbName,
                    liveDatabase: $liveDatabase,
                    timeoutSeconds: $timeoutSeconds,
                );
            }

            $this->removeDirectoryRecursive($tempExtractDir);
        }
    }

    /**
     * Build the read-only Owner Dashboard backup status snapshot from ActivityLog using integer hours only.
     *
     * @return array{
     *     level: string,
     *     has_completed_backup: bool,
     *     last_completed_at_formatted: ?string,
     *     last_size_formatted: ?string,
     *     age_hours: ?int,
     *     max_age_hours: int,
     *     half_max_age_hours: int,
     *     show_failure_notice: bool,
     *     last_failed_at_formatted: ?string
     * }
     */
    public function getDashboardStatus(?CarbonInterface $now = null, ?string $timezone = null): array
    {
        $tz = $timezone ?: $this->resolveShopTimezone();
        $referenceNow = ($now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now())
            ->setTimezone($tz);

        $maxAgeHours = max(2, (int) config('backup.max_age_hours', 26));
        $halfMaxAgeHours = intdiv($maxAgeHours, 2);

        $latestLogs = ActivityLog::query()
            ->where('module', 'system')
            ->whereIn('action', ['backup.completed', 'backup.failed'])
            ->whereIn('id', function ($sub): void {
                $sub->selectRaw('MAX(id)')
                    ->from('activity_logs')
                    ->where('module', 'system')
                    ->whereIn('action', ['backup.completed', 'backup.failed'])
                    ->groupBy('action');
            })
            ->get()
            ->keyBy('action');

        /** @var ?ActivityLog $latestCompleted */
        $latestCompleted = $latestLogs->get('backup.completed');
        /** @var ?ActivityLog $latestFailed */
        $latestFailed = $latestLogs->get('backup.failed');

        $showFailureNotice = false;
        $lastFailedFormatted = null;

        if ($latestFailed !== null && $latestFailed->created_at !== null) {
            $failedAt = CarbonImmutable::instance($latestFailed->created_at)->setTimezone($tz);
            $lastFailedFormatted = $failedAt->locale(app()->getLocale())->translatedFormat('d M Y, H:i');

            if ($latestCompleted === null || $latestCompleted->created_at === null) {
                $showFailureNotice = true;
            } else {
                $completedAtForCompare = CarbonImmutable::instance($latestCompleted->created_at)->setTimezone($tz);
                if ($failedAt->gt($completedAtForCompare) || ($failedAt->eq($completedAtForCompare) && $latestFailed->id > $latestCompleted->id)) {
                    $showFailureNotice = true;
                }
            }
        }

        if ($latestCompleted === null || $latestCompleted->created_at === null) {
            return [
                'level' => 'danger',
                'has_completed_backup' => false,
                'last_completed_at_formatted' => null,
                'last_size_formatted' => null,
                'age_hours' => null,
                'max_age_hours' => $maxAgeHours,
                'half_max_age_hours' => $halfMaxAgeHours,
                'show_failure_notice' => $showFailureNotice,
                'last_failed_at_formatted' => $lastFailedFormatted,
            ];
        }

        $completedAt = CarbonImmutable::instance($latestCompleted->created_at)->setTimezone($tz);
        $diffSeconds = max(0, $referenceNow->getTimestamp() - $completedAt->getTimestamp());
        $ageHours = intdiv($diffSeconds, 3600);

        $props = is_array($latestCompleted->properties) ? $latestCompleted->properties : [];
        $sizeBytes = (int) ($props['size_bytes'] ?? 0);

        $level = 'ok';
        if ($ageHours >= $maxAgeHours) {
            $level = 'danger';
        } elseif ($ageHours >= $halfMaxAgeHours) {
            $level = 'warning';
        }

        return [
            'level' => $level,
            'has_completed_backup' => true,
            'last_completed_at_formatted' => $completedAt->locale(app()->getLocale())->translatedFormat('d M Y, H:i'),
            'last_size_formatted' => self::formatBytesInteger($sizeBytes),
            'age_hours' => $ageHours,
            'max_age_hours' => $maxAgeHours,
            'half_max_age_hours' => $halfMaxAgeHours,
            'show_failure_notice' => $showFailureNotice,
            'last_failed_at_formatted' => $lastFailedFormatted,
        ];
    }

    /**
     * Format a byte count into a human-readable integer string (B, KB, MB) using integer arithmetic only.
     */
    public static function formatBytesInteger(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $kb = intdiv($bytes + 512, 1024);
        if ($kb < 1024) {
            return number_format($kb, 0, ',', '.').' KB';
        }

        $mbTenths = intdiv(($bytes * 10) + 524288, 1048576);
        $mbWhole = intdiv($mbTenths, 10);
        $mbFrac = $mbTenths % 10;

        return "{$mbWhole}.{$mbFrac} MB";
    }

    /**
     * Build the exact argument array for mysqldump (never includes the database secret).
     *
     * @return list<string>
     */
    public function buildMysqldumpCommand(
        string $binary,
        string $host,
        string $port,
        string $username,
        string $database,
        string $resultFile,
    ): array {
        return [
            $binary,
            '--single-transaction',
            '--routines',
            '--triggers',
            '--no-tablespaces',
            '--default-character-set=utf8mb4',
            "--host={$host}",
            "--port={$port}",
            "--user={$username}",
            "--result-file={$resultFile}",
            $database,
        ];
    }

    /**
     * Build the exact argument array for the mysql client to execute a SQL statement (never includes the secret).
     *
     * @return list<string>
     */
    public function buildMysqlExecuteCommand(
        string $binary,
        string $host,
        string $port,
        string $username,
        string $sql,
        ?string $database = null,
    ): array {
        $cmd = [
            $binary,
            '--batch',
            '--skip-column-names',
            '--default-character-set=utf8mb4',
            "--host={$host}",
            "--port={$port}",
            "--user={$username}",
        ];

        if ($database !== null && $database !== '') {
            $cmd[] = $database;
        }

        $cmd[] = '-e';
        $cmd[] = $sql;

        return $cmd;
    }

    /**
     * Verify a generated SQL dump file: must exist, have size >= $minDumpBytes,
     * contain at least one CREATE TABLE, and have "Dump completed" on its last non-empty line.
     */
    public function verifySqlDumpFile(string $sqlPath, int $minDumpBytes = 1024): ?string
    {
        if (! is_file($sqlPath)) {
            return 'mysqldump did not produce an output SQL file.';
        }

        $size = (int) filesize($sqlPath);
        if ($size < $minDumpBytes) {
            return "SQL dump file is too small ({$size} bytes, minimum required is {$minDumpBytes} bytes).";
        }

        $contents = (string) file_get_contents($sqlPath);
        if (stripos($contents, 'CREATE TABLE') === false) {
            return 'SQL dump verification failed: file does not contain any CREATE TABLE statement.';
        }

        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $lastNonEmptyLine = '';
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $trimmed = trim($lines[$i]);
            if ($trimmed !== '') {
                $lastNonEmptyLine = $trimmed;
                break;
            }
        }

        if (! str_contains($lastNonEmptyLine, 'Dump completed')) {
            return 'SQL dump verification failed: last non-empty line does not contain "Dump completed".';
        }

        return null;
    }

    /**
     * Apply backup retention in a folder: delete only files matching `temu_pulang_*.zip`
     * older than $retentionDays, while ALWAYS keeping the newest 3 `temu_pulang_*.zip` files.
     */
    public function applyRetention(string $directory, int $retentionDays, ?CarbonInterface $now = null): int
    {
        if (! is_dir($directory)) {
            return 0;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return 0;
        }

        $matchingFiles = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (preg_match('/^temu_pulang_\d{8}_\d{6}\.zip$/', $entry) !== 1) {
                continue;
            }

            $fullPath = $directory.DIRECTORY_SEPARATOR.$entry;
            if (! is_file($fullPath)) {
                continue;
            }

            $matchingFiles[] = [
                'name' => $entry,
                'path' => $fullPath,
                'mtime' => (int) filemtime($fullPath),
            ];
        }

        // Sort newest first by filename (which embeds YYYYmmdd_HHMMSS), then mtime DESC
        usort($matchingFiles, static function (array $a, array $b): int {
            $cmp = strcmp($b['name'], $a['name']);
            if ($cmp !== 0) {
                return $cmp;
            }

            return $b['mtime'] <=> $a['mtime'];
        });

        if (count($matchingFiles) <= self::MIN_RETAINED_BACKUPS) {
            return 0;
        }

        $referenceNow = $now !== null ? CarbonImmutable::instance($now) : CarbonImmutable::now();
        $cutoffTimestamp = $referenceNow->subDays(max(1, $retentionDays))->getTimestamp();

        $deletedCount = 0;
        $survivors = array_slice($matchingFiles, self::MIN_RETAINED_BACKUPS);

        foreach ($survivors as $candidate) {
            if ($candidate['mtime'] < $cutoffTimestamp) {
                if (@unlink($candidate['path'])) {
                    $deletedCount++;
                }
            }
        }

        return $deletedCount;
    }

    /**
     * Scrub the database secret from any string before printing or logging.
     */
    public function scrubSecret(string $text, string $secret): string
    {
        if ($secret === '') {
            return $text;
        }

        return str_replace($secret, '[REDACTED]', $text);
    }

    /**
     * Generate a random temporary database name starting with `temu_restore_check_`.
     */
    public function generateRestoreCheckDatabaseName(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $suffix = '';
        for ($i = 0; $i < 10; $i++) {
            $suffix .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return self::RESTORE_CHECK_DB_PREFIX.$suffix;
    }

    /**
     * Validate that a restore-check database name starts with `temu_restore_check_`,
     * contains only lowercase letters and digits after the prefix, and never equals the live database name.
     */
    public function assertSafeRestoreCheckDatabaseName(string $tempDbName, string $liveDatabase): void
    {
        if ($tempDbName === '' || strcasecmp($tempDbName, trim($liveDatabase)) === 0) {
            throw new RuntimeException(
                "Refusing to run restore check: temporary database name '{$tempDbName}' matches the live database name."
            );
        }

        if (! str_starts_with($tempDbName, self::RESTORE_CHECK_DB_PREFIX)) {
            throw new RuntimeException(
                "Refusing to run restore check: temporary database name '{$tempDbName}' does not start with '".self::RESTORE_CHECK_DB_PREFIX."'."
            );
        }

        $suffix = substr($tempDbName, strlen(self::RESTORE_CHECK_DB_PREFIX));
        if ($suffix === '' || preg_match('/^[a-z0-9]+$/', $suffix) !== 1) {
            throw new RuntimeException(
                "Refusing to run restore check: temporary database name '{$tempDbName}' must end with lowercase letters and digits."
            );
        }
    }

    /**
     * Check whether the configured mysqldump binary can be located as a file or on the system PATH.
     */
    public static function isBinaryDiscoverable(string $configuredBinary): bool
    {
        $trimmed = trim($configuredBinary, " \t\n\r\0\x0B\"'");
        if ($trimmed === '') {
            return false;
        }

        if (is_file($trimmed)) {
            return true;
        }

        if (str_contains($trimmed, '/') || str_contains($trimmed, '\\')) {
            return false;
        }

        $pathEnv = (string) getenv('PATH');
        if ($pathEnv === '') {
            return false;
        }

        $dirs = explode(PATH_SEPARATOR, $pathEnv);
        $isWindows = DIRECTORY_SEPARATOR === '\\';
        $extensions = $isWindows ? ['', '.exe', '.cmd', '.bat'] : [''];

        foreach ($dirs as $dir) {
            $cleanDir = rtrim(trim($dir, '"'), '\\/');
            if ($cleanDir === '') {
                continue;
            }
            foreach ($extensions as $ext) {
                if (is_file($cleanDir.DIRECTORY_SEPARATOR.$trimmed.$ext)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Package database.sql, manifest.txt, and optional qris/ directory into the target zip archive.
     */
    private function createBackupZipArchive(string $zipFilePath, string $tempSqlPath, string $manifestText): void
    {
        $zip = new ZipArchive;
        $opened = $zip->open($zipFilePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        if ($opened !== true) {
            throw new RuntimeException('Could not open ZipArchive for writing.');
        }

        if (! $zip->addFile($tempSqlPath, 'database.sql')) {
            $zip->close();
            throw new RuntimeException('Failed to add database.sql to backup zip archive.');
        }

        if (! $zip->addFromString('manifest.txt', $manifestText)) {
            $zip->close();
            throw new RuntimeException('Failed to add manifest.txt to backup zip archive.');
        }

        $qrisDir = storage_path('app/public/qris');
        if (is_dir($qrisDir)) {
            $this->addDirectoryToZip($zip, $qrisDir, 'qris');
        }

        if (! $zip->close()) {
            throw new RuntimeException('Failed to finalize backup zip archive.');
        }
    }

    /**
     * Recursively add files from a directory into a ZipArchive under $zipPrefix.
     */
    private function addDirectoryToZip(ZipArchive $zip, string $sourceDir, string $zipPrefix): void
    {
        $entries = scandir($sourceDir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $sourceDir.DIRECTORY_SEPARATOR.$entry;
            $entryZipPath = $zipPrefix.'/'.$entry;

            if (is_dir($fullPath)) {
                $this->addDirectoryToZip($zip, $fullPath, $entryZipPath);
            } elseif (is_file($fullPath)) {
                $zip->addFile($fullPath, $entryZipPath);
            }
        }
    }

    /**
     * Build the plain-text manifest.txt content (never contains passwords or secrets).
     */
    private function buildManifest(
        CarbonImmutable $timestamp,
        string $database,
        int $tableCount,
        int $dumpSizeBytes,
    ): string {
        $appName = (string) config('app.name', 'Temu Pulang');

        return implode("\n", [
            'Temu Pulang Backup Manifest',
            '===========================',
            'Timestamp      : '.$timestamp->format('Y-m-d H:i:s T'),
            'Application    : '.$appName,
            'Database       : '.$database,
            'Table Count    : '.$tableCount,
            'Dump Size (B)  : '.$dumpSizeBytes,
            'Includes .env  : no (store .env separately offline)',
            '',
        ]);
    }

    /**
     * Count the number of CREATE TABLE statements inside a verified SQL dump file.
     */
    private function countCreateTableStatements(string $sqlPath): int
    {
        $contents = (string) file_get_contents($sqlPath);

        return preg_match_all('/CREATE\s+TABLE\b/i', $contents);
    }

    /**
     * Resolve the target backup zip archive path from an explicit `--file` option or the newest `temu_pulang_*.zip`.
     */
    private function resolveBackupZipPath(string $backupDir, ?string $fileOption): ?string
    {
        if ($fileOption !== null && trim($fileOption) !== '') {
            $candidate = trim($fileOption);
            if (is_file($candidate)) {
                return $candidate;
            }

            $inBackupDir = $backupDir.DIRECTORY_SEPARATOR.basename($candidate);
            if (is_file($inBackupDir)) {
                return $inBackupDir;
            }

            return null;
        }

        if (! is_dir($backupDir)) {
            return null;
        }

        $entries = scandir($backupDir);
        if ($entries === false) {
            return null;
        }

        $zips = [];
        foreach ($entries as $entry) {
            if (preg_match('/^temu_pulang_\d{8}_\d{6}\.zip$/', $entry) === 1) {
                $full = $backupDir.DIRECTORY_SEPARATOR.$entry;
                if (is_file($full)) {
                    $zips[] = $full;
                }
            }
        }

        if ($zips === []) {
            return null;
        }

        usort($zips, static fn (string $a, string $b): int => strcmp(basename($b), basename($a)));

        return $zips[0];
    }

    /**
     * Query table counts and key table row counts (`orders`, `payment_transactions`, `stock_movements`)
     * for both the live database and the restored temporary database.
     *
     * @return array{
     *     live_tables_count: int,
     *     restored_tables_count: int,
     *     row_counts: array<string, array{live: int, restored: int, diff: int}>
     * }
     */
    private function queryRestoreComparisonStats(
        string $binary,
        string $host,
        string $port,
        string $username,
        string $secret,
        string $liveDatabase,
        string $restoredDatabase,
        int $timeoutSeconds,
    ): array {
        $sql = implode(' ', [
            "SELECT 'live_tables', COUNT(*) FROM information_schema.tables WHERE table_schema = '{$liveDatabase}'",
            "UNION ALL SELECT 'restored_tables', COUNT(*) FROM information_schema.tables WHERE table_schema = '{$restoredDatabase}'",
            "UNION ALL SELECT 'live_orders', COUNT(*) FROM `{$liveDatabase}`.`orders`",
            "UNION ALL SELECT 'restored_orders', COUNT(*) FROM `{$restoredDatabase}`.`orders`",
            "UNION ALL SELECT 'live_payment_transactions', COUNT(*) FROM `{$liveDatabase}`.`payment_transactions`",
            "UNION ALL SELECT 'restored_payment_transactions', COUNT(*) FROM `{$restoredDatabase}`.`payment_transactions`",
            "UNION ALL SELECT 'live_stock_movements', COUNT(*) FROM `{$liveDatabase}`.`stock_movements`",
            "UNION ALL SELECT 'restored_stock_movements', COUNT(*) FROM `{$restoredDatabase}`.`stock_movements`;",
        ]);

        $cmd = $this->buildMysqlExecuteCommand(
            binary: $binary,
            host: $host,
            port: $port,
            username: $username,
            sql: $sql,
        );

        $result = Process::timeout($timeoutSeconds)
            ->env(['MYSQL_PWD' => $secret])
            ->run($cmd);

        if (! $result->successful()) {
            $err = $this->scrubSecret(trim($result->errorOutput().' '.$result->output()), $secret);
            throw new RuntimeException("Failed to compare live vs restored database statistics: {$err}");
        }

        $parsed = [
            'live_tables' => 0,
            'restored_tables' => 0,
            'live_orders' => 0,
            'restored_orders' => 0,
            'live_payment_transactions' => 0,
            'restored_payment_transactions' => 0,
            'live_stock_movements' => 0,
            'restored_stock_movements' => 0,
        ];

        $lines = preg_split('/\r\n|\r|\n/', trim($result->output())) ?: [];
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (is_array($parts) && count($parts) >= 2 && array_key_exists($parts[0], $parsed)) {
                $parsed[$parts[0]] = (int) $parts[1];
            }
        }

        $tables = ['orders', 'payment_transactions', 'stock_movements'];
        $rowCounts = [];
        foreach ($tables as $tbl) {
            $liveVal = $parsed["live_{$tbl}"];
            $restoredVal = $parsed["restored_{$tbl}"];
            $rowCounts[$tbl] = [
                'live' => $liveVal,
                'restored' => $restoredVal,
                'diff' => $liveVal - $restoredVal,
            ];
        }

        return [
            'live_tables_count' => $parsed['live_tables'],
            'restored_tables_count' => $parsed['restored_tables'],
            'row_counts' => $rowCounts,
        ];
    }

    /**
     * Drop the temporary restore-check database after re-verifying the safety prefix and live DB inequality.
     */
    private function dropTemporaryRestoreDatabase(
        string $binary,
        string $host,
        string $port,
        string $username,
        string $secret,
        string $tempDbName,
        string $liveDatabase,
        int $timeoutSeconds,
    ): void {
        try {
            $this->assertSafeRestoreCheckDatabaseName($tempDbName, $liveDatabase);

            $dropCmd = $this->buildMysqlExecuteCommand(
                binary: $binary,
                host: $host,
                port: $port,
                username: $username,
                sql: "DROP DATABASE IF EXISTS `{$tempDbName}`;",
            );

            Process::timeout($timeoutSeconds)
                ->env(['MYSQL_PWD' => $secret])
                ->run($dropCmd);
        } catch (Throwable) {
            // Never mask the original verification outcome during finally cleanup.
        }
    }

    /**
     * Record a system ActivityLog entry safely without throwing if the database is unreachable.
     *
     * @param  array<string, mixed>  $properties
     */
    private function recordActivity(string $action, string $description, array $properties): void
    {
        try {
            ActivityLog::record(
                action: $action,
                description: $description,
                properties: $properties,
                module: 'system',
            );
        } catch (Throwable) {
            // Ignore logging error if database is unavailable.
        }
    }

    /**
     * Resolve the active shop timezone (fallback 'Asia/Jakarta').
     */
    private function resolveShopTimezone(): string
    {
        try {
            $setting = ShopSetting::where('is_active', true)->first();
            if ($setting !== null && ! empty($setting->timezone)) {
                return (string) $setting->timezone;
            }
        } catch (Throwable) {
            // Fall through to app.timezone fallback.
        }

        return (string) config('app.timezone', 'Asia/Jakarta');
    }

    /**
     * Ensure a directory exists and is writable.
     */
    private function ensureDirectoryExists(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory '{$dir}'.");
        }

        if (! is_writable($dir)) {
            throw new RuntimeException("Directory '{$dir}' is not writable.");
        }
    }

    /**
     * Delete a file if it exists.
     */
    private function safeUnlink(string $path): void
    {
        if ($path !== '' && is_file($path)) {
            @unlink($path);
        }
    }

    /**
     * Recursively remove a temporary directory.
     */
    private function removeDirectoryRecursive(string $dir): void
    {
        if ($dir === '' || ! is_dir($dir)) {
            return;
        }

        $entries = scandir($dir);
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $full = $dir.DIRECTORY_SEPARATOR.$entry;
                if (is_dir($full)) {
                    $this->removeDirectoryRecursive($full);
                } else {
                    @unlink($full);
                }
            }
        }

        @rmdir($dir);
    }

    /**
     * Compute elapsed whole seconds using integer nanosecond clock.
     */
    private function elapsedSeconds(int $startedAtNs): int
    {
        $diffNs = max(0, hrtime(true) - $startedAtNs);

        return intdiv($diffNs, 1_000_000_000);
    }
}

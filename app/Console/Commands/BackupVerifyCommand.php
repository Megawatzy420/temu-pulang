<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;
use Throwable;

class BackupVerifyCommand extends Command
{
    protected $signature = 'temupulang:backup-verify {--file= : Specific backup zip filename or path to verify}';

    protected $description = 'Verify a backup zip archive by restoring database.sql into a temporary scratch database.';

    public function handle(BackupService $backupService): int
    {
        $fileOption = $this->option('file');
        $fileStr = is_string($fileOption) && trim($fileOption) !== '' ? trim($fileOption) : null;

        try {
            $result = $backupService->verifyBackup(fileOption: $fileStr);
        } catch (Throwable $e) {
            $secret = (string) config('database.connections.mysql.password', '');
            $scrubbed = $backupService->scrubSecret($e->getMessage(), $secret);
            $this->error("[FAIL] {$scrubbed}");

            return self::FAILURE;
        }

        if (! $result['success']) {
            $this->error('[FAIL] Backup verification failed: '.($result['error'] ?? 'Unknown error.'));

            return self::FAILURE;
        }

        $this->info("[PASS] Verified backup archive: {$result['file_name']} (scratch DB: {$result['temp_database']}, {$result['duration_seconds']}s)");
        $this->line("Tables count — Live: {$result['live_tables_count']} | Restored: {$result['restored_tables_count']}");

        foreach ($result['row_counts'] as $table => $counts) {
            $diffSign = $counts['diff'] >= 0 ? '+'.$counts['diff'] : (string) $counts['diff'];
            $this->line("Row count [{$table}] — Live: {$counts['live']} | Restored: {$counts['restored']} (diff: {$diffSign})");
        }

        return self::SUCCESS;
    }
}

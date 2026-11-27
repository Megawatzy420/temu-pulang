<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class BackupCommand extends Command
{
    protected $signature = 'temupulang:backup {--no-copy : Skip copying the backup archive to BACKUP_COPY_PATH}';

    protected $description = 'Create and verify a timestamped MySQL database and QRIS asset backup zip archive.';

    public function handle(BackupService $backupService): int
    {
        $noCopy = (bool) $this->option('no-copy');
        $result = $backupService->runBackup(noCopy: $noCopy);

        if (! $result['success']) {
            $this->error('[FAIL] Backup failed: '.($result['error'] ?? 'Unknown error.'));

            return self::FAILURE;
        }

        $formattedSize = BackupService::formatBytesInteger($result['size_bytes']);
        $this->info("[PASS] Backup created: {$result['file_name']} ({$formattedSize}, {$result['size_bytes']} bytes) in {$result['duration_seconds']}s.");

        if ($result['copy_status'] === 'copied') {
            $this->line('Secondary copy: copied successfully.');
        } elseif ($result['copy_status'] === 'failed') {
            $this->warn('Secondary copy: [WARN] failed — '.($result['copy_error'] ?? 'Could not copy archive.'));
        } else {
            $this->line('Secondary copy: skipped.');
        }

        $this->line("Retention pruned: {$result['deleted_primary']} primary file(s), {$result['deleted_copy']} secondary copy file(s).");

        return self::SUCCESS;
    }
}

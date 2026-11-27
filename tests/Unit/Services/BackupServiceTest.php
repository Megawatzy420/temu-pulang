<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\BackupService;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

class BackupServiceTest extends TestCase
{
    private BackupService $backupService;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupService = app(BackupService::class);
        $this->tempDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tp_backup_unit_'.bin2hex(random_bytes(5));
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->cleanupDir($this->tempDir);
        parent::tearDown();
    }

    public function test_build_mysqldump_and_mysql_commands_never_contain_secret_in_arguments(): void
    {
        $dumpCmd = $this->backupService->buildMysqldumpCommand(
            binary: 'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            host: '127.0.0.1',
            port: '3306',
            username: 'temupulang_user',
            database: 'temu_pulang',
            resultFile: 'C:\\backups\\temp.sql',
        );

        $this->assertContains('--single-transaction', $dumpCmd);
        $this->assertContains('--routines', $dumpCmd);
        $this->assertContains('--triggers', $dumpCmd);
        $this->assertContains('--no-tablespaces', $dumpCmd);
        $this->assertContains('--default-character-set=utf8mb4', $dumpCmd);
        $this->assertContains('--host=127.0.0.1', $dumpCmd);
        $this->assertContains('--port=3306', $dumpCmd);
        $this->assertContains('--user=temupulang_user', $dumpCmd);
        $this->assertContains('--result-file=C:\\backups\\temp.sql', $dumpCmd);
        $this->assertContains('temu_pulang', $dumpCmd);

        foreach ($dumpCmd as $arg) {
            $this->assertStringNotContainsString('password', strtolower($arg));
            $this->assertFalse(str_starts_with($arg, '-p'));
        }

        $execCmd = $this->backupService->buildMysqlExecuteCommand(
            binary: 'mysql',
            host: '127.0.0.1',
            port: '3306',
            username: 'temupulang_user',
            sql: 'SELECT 1;',
            database: 'temu_restore_check_abc12345',
        );

        $this->assertContains('--batch', $execCmd);
        $this->assertContains('--skip-column-names', $execCmd);
        $this->assertContains('temu_restore_check_abc12345', $execCmd);
        $this->assertContains('-e', $execCmd);
        $this->assertContains('SELECT 1;', $execCmd);
    }

    public function test_verify_sql_dump_file_checks_existence_min_size_create_table_and_dump_completed_footer(): void
    {
        $missingPath = $this->tempDir.DIRECTORY_SEPARATOR.'missing.sql';
        $this->assertNotNull($this->backupService->verifySqlDumpFile($missingPath, 100));

        // 1. Too small
        $smallPath = $this->tempDir.DIRECTORY_SEPARATOR.'small.sql';
        file_put_contents($smallPath, "CREATE TABLE `t` (`id` int);\n-- Dump completed on 2026-11-27\n");
        $this->assertStringContainsString('too small', (string) $this->backupService->verifySqlDumpFile($smallPath, 1024));

        // 2. Missing CREATE TABLE
        $noCreatePath = $this->tempDir.DIRECTORY_SEPARATOR.'no_create.sql';
        file_put_contents($noCreatePath, str_repeat('-- header comment ', 80)."\n-- Dump completed on 2026-11-27\n");
        $this->assertStringContainsString('CREATE TABLE', (string) $this->backupService->verifySqlDumpFile($noCreatePath, 100));

        // 3. Missing Dump completed on last non-empty line
        $noFooterPath = $this->tempDir.DIRECTORY_SEPARATOR.'no_footer.sql';
        file_put_contents($noFooterPath, str_repeat('-- padding ', 100)."\nCREATE TABLE `orders` (`id` bigint);\n-- Aborted mid-dump\n");
        $this->assertStringContainsString('Dump completed', (string) $this->backupService->verifySqlDumpFile($noFooterPath, 100));

        // 4. Valid dump passes (returns null)
        $validPath = $this->tempDir.DIRECTORY_SEPARATOR.'valid.sql';
        file_put_contents(
            $validPath,
            str_repeat('-- valid dump header ', 60)."\nCREATE TABLE `orders` (`id` bigint);\n-- Dump completed on 2026-11-27 03:30:00\n\n"
        );
        $this->assertNull($this->backupService->verifySqlDumpFile($validPath, 100));
    }

    public function test_apply_retention_deletes_old_matching_files_keeps_newest_three_and_ignores_other_files(): void
    {
        $now = CarbonImmutable::parse('2026-11-27 12:00:00', 'Asia/Jakarta');

        // Create 5 matching backup zips, all 30 days old (older than 14-day retention)
        $files = [
            'temu_pulang_20261001_033000.zip',
            'temu_pulang_20261005_033000.zip',
            'temu_pulang_20261010_033000.zip',
            'temu_pulang_20261015_033000.zip',
            'temu_pulang_20261020_033000.zip',
        ];

        $thirtyDaysAgo = $now->subDays(30)->getTimestamp();
        foreach ($files as $name) {
            $p = $this->tempDir.DIRECTORY_SEPARATOR.$name;
            file_put_contents($p, 'zip-content');
            touch($p, $thirtyDaysAgo);
        }

        // Also create non-matching files that are 60 days old (must NEVER be touched)
        $otherFile1 = $this->tempDir.DIRECTORY_SEPARATOR.'notes.txt';
        $otherFile2 = $this->tempDir.DIRECTORY_SEPARATOR.'custom_backup_20260101.zip';
        file_put_contents($otherFile1, 'keep me');
        file_put_contents($otherFile2, 'keep me too');
        touch($otherFile1, $now->subDays(60)->getTimestamp());
        touch($otherFile2, $now->subDays(60)->getTimestamp());

        $deleted = $this->backupService->applyRetention($this->tempDir, 14, $now);

        // Out of 5 old backups, the newest 3 MUST survive and only the oldest 2 are deleted
        $this->assertSame(2, $deleted);
        $this->assertFileDoesNotExist($this->tempDir.DIRECTORY_SEPARATOR.'temu_pulang_20261001_033000.zip');
        $this->assertFileDoesNotExist($this->tempDir.DIRECTORY_SEPARATOR.'temu_pulang_20261005_033000.zip');
        $this->assertFileExists($this->tempDir.DIRECTORY_SEPARATOR.'temu_pulang_20261010_033000.zip');
        $this->assertFileExists($this->tempDir.DIRECTORY_SEPARATOR.'temu_pulang_20261015_033000.zip');
        $this->assertFileExists($this->tempDir.DIRECTORY_SEPARATOR.'temu_pulang_20261020_033000.zip');

        // Non-matching files untouched
        $this->assertFileExists($otherFile1);
        $this->assertFileExists($otherFile2);
    }

    public function test_restore_check_database_name_generator_and_safety_guard(): void
    {
        $generated = $this->backupService->generateRestoreCheckDatabaseName();
        $this->assertMatchesRegularExpression('/^temu_restore_check_[a-z0-9]{10}$/', $generated);

        // Valid name passes
        $this->backupService->assertSafeRestoreCheckDatabaseName($generated, 'temu_pulang');

        // Refuses when equal to live database name
        try {
            $this->backupService->assertSafeRestoreCheckDatabaseName('temu_restore_check_live', 'temu_restore_check_live');
            $this->fail('Expected RuntimeException when temp DB equals live DB.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('matches the live database name', $e->getMessage());
        }

        // Refuses when prefix does not match
        try {
            $this->backupService->assertSafeRestoreCheckDatabaseName('temu_pulang', 'other_db');
            $this->fail('Expected RuntimeException when temp DB lacks prefix.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('temu_restore_check_', $e->getMessage());
        }
    }

    public function test_scrub_secret_and_format_bytes_integer(): void
    {
        $secret = 'TopSecretDbCredential#99!';
        $raw = "Access denied for user 'root' using '{$secret}' on host 127.0.0.1";
        $scrubbed = $this->backupService->scrubSecret($raw, $secret);

        $this->assertStringNotContainsString($secret, $scrubbed);
        $this->assertStringContainsString('[REDACTED]', $scrubbed);

        $this->assertSame('0 B', BackupService::formatBytesInteger(0));
        $this->assertSame('512 B', BackupService::formatBytesInteger(512));
        $this->assertSame('2 KB', BackupService::formatBytesInteger(2048));
        $this->assertSame('1.5 MB', BackupService::formatBytesInteger(1_572_864));
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($full)) {
                $this->cleanupDir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($dir);
    }
}

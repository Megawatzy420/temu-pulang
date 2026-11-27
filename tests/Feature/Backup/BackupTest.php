<?php

declare(strict_types=1);

namespace Tests\Feature\Backup;

use App\Livewire\Dashboard\OwnerDashboard;
use App\Models\ActivityLog;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\BackupService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Database\Seeders\ShopSettingSeeder;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $tempRoot;

    private string $primaryBackupDir;

    private string $copyBackupDir;

    private string $originalEnv;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnv = (string) $this->app['env'];

        $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tp_backup_feat_'.bin2hex(random_bytes(5));
        $this->primaryBackupDir = $this->tempRoot.DIRECTORY_SEPARATOR.'primary';
        $this->copyBackupDir = $this->tempRoot.DIRECTORY_SEPARATOR.'copy';

        mkdir($this->primaryBackupDir, 0755, true);
        mkdir($this->copyBackupDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->originalEnv;
        $this->cleanupDir($this->tempRoot);
        parent::tearDown();
    }

    public function test_backup_command_builds_mysqldump_passes_secret_only_via_env_creates_zip_with_qris_and_manifest_and_removes_temp_sql(): void
    {
        $this->seed(DatabaseSeeder::class);

        $distinctiveSecret = 'UltraSecretCafeMysql#2026!';
        $this->configureMysqlForFakeProcess($distinctiveSecret);

        // Put a sample QRIS file in storage/app/public/qris
        $qrisDir = storage_path('app/public/qris');
        if (! is_dir($qrisDir)) {
            mkdir($qrisDir, 0755, true);
        }
        $sampleQrisFile = $qrisDir.DIRECTORY_SEPARATOR.'test_backup_qris.png';
        file_put_contents($sampleQrisFile, 'fake-png-qris-payload');

        $capturedCommand = null;
        $capturedEnv = null;

        $validDumpContent = str_repeat("-- MySQL dump comment line\n", 50)
            ."CREATE TABLE `orders` (`id` bigint unsigned NOT NULL);\n"
            ."CREATE TABLE `payments` (`id` bigint unsigned NOT NULL);\n"
            ."-- Dump completed on 2026-11-27 03:30:05\n";

        Process::fake(function (PendingProcess $process) use (&$capturedCommand, &$capturedEnv, $validDumpContent): ProcessResult {
            $capturedCommand = $process->command;
            $capturedEnv = $process->environment;

            // Write the fake dump into the --result-file path
            if (is_array($process->command)) {
                foreach ($process->command as $arg) {
                    if (str_starts_with((string) $arg, '--result-file=')) {
                        $targetFile = substr((string) $arg, strlen('--result-file='));
                        file_put_contents($targetFile, $validDumpContent);
                    }
                }
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        try {
            $exitCode = Artisan::call('temupulang:backup');
            $output = Artisan::output();

            $this->assertSame(0, $exitCode, $output);
            $this->assertStringContainsString('[PASS] Backup created:', $output);
            $this->assertStringContainsString('Secondary copy: copied successfully.', $output);
            $this->assertStringNotContainsString($distinctiveSecret, $output);

            // 1. Verify command arguments and MYSQL_PWD environment variable
            $this->assertIsArray($capturedCommand);
            $this->assertContains('--single-transaction', $capturedCommand);
            $this->assertContains('--routines', $capturedCommand);
            $this->assertContains('--triggers', $capturedCommand);
            $this->assertContains('--no-tablespaces', $capturedCommand);
            $this->assertContains('--default-character-set=utf8mb4', $capturedCommand);
            $this->assertNotContains($distinctiveSecret, $capturedCommand);
            $this->assertStringNotContainsString($distinctiveSecret, implode(' ', $capturedCommand));
            $this->assertSame($distinctiveSecret, $capturedEnv['MYSQL_PWD'] ?? null);

            // 2. Verify primary zip and secondary copy exist, and no temp_*.sql remains
            $primaryFiles = array_values(array_diff(scandir($this->primaryBackupDir) ?: [], ['.', '..']));
            $this->assertCount(1, $primaryFiles);
            $zipName = $primaryFiles[0];
            $this->assertMatchesRegularExpression('/^temu_pulang_\d{8}_\d{6}\.zip$/', $zipName);

            $primaryZipPath = $this->primaryBackupDir.DIRECTORY_SEPARATOR.$zipName;
            $copyZipPath = $this->copyBackupDir.DIRECTORY_SEPARATOR.$zipName;
            $this->assertFileExists($primaryZipPath);
            $this->assertFileExists($copyZipPath);

            // 2b. Verify retention in BOTH primary and copy folders:
            // Seed 3 old matching zips (30 days old) + 1 old non-matching file in both primary and copy folders.
            // Together with today's new backup ($zipName), there are 4 matching zips in each folder.
            // Running applyRetention keeps the newest 3 (today's + 2 of the old ones), deletes the oldest 1 in each folder,
            // and never touches the non-matching file!
            $thirtyDaysAgo = CarbonImmutable::now()->subDays(30)->getTimestamp();
            $oldMatching = [
                'temu_pulang_20261001_033000.zip',
                'temu_pulang_20261005_033000.zip',
                'temu_pulang_20261010_033000.zip',
            ];
            foreach ([$this->primaryBackupDir, $this->copyBackupDir] as $dir) {
                foreach ($oldMatching as $oldName) {
                    $p = $dir.DIRECTORY_SEPARATOR.$oldName;
                    file_put_contents($p, 'old-zip');
                    touch($p, $thirtyDaysAgo);
                }
                $unrelated = $dir.DIRECTORY_SEPARATOR.'keep_forever.txt';
                file_put_contents($unrelated, 'do not delete');
                touch($unrelated, $thirtyDaysAgo);
            }

            $svc = app(BackupService::class);
            $this->assertSame(1, $svc->applyRetention($this->primaryBackupDir, 14));
            $this->assertSame(1, $svc->applyRetention($this->copyBackupDir, 14));

            foreach ([$this->primaryBackupDir, $this->copyBackupDir] as $dir) {
                $this->assertFileDoesNotExist($dir.DIRECTORY_SEPARATOR.'temu_pulang_20261001_033000.zip');
                $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'temu_pulang_20261005_033000.zip');
                $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'temu_pulang_20261010_033000.zip');
                $this->assertFileExists($dir.DIRECTORY_SEPARATOR.$zipName);
                $this->assertFileExists($dir.DIRECTORY_SEPARATOR.'keep_forever.txt');
            }

            // 3. Inspect zip contents: database.sql, manifest.txt, qris/test_backup_qris.png, NO .env, NO secret
            $zip = new ZipArchive;
            $this->assertSame(true, $zip->open($primaryZipPath));
            $this->assertNotFalse($zip->locateName('database.sql'));
            $this->assertNotFalse($zip->locateName('manifest.txt'));
            $this->assertNotFalse($zip->locateName('qris/test_backup_qris.png'));
            $this->assertFalse($zip->locateName('.env'));

            $manifestText = (string) $zip->getFromName('manifest.txt');
            $this->assertStringContainsString('Table Count    : 2', $manifestText);
            $this->assertStringNotContainsString($distinctiveSecret, $manifestText);
            $zip->close();

            // 4. Verify ActivityLog entry has no secrets
            $log = ActivityLog::where('module', 'system')
                ->where('action', 'backup.completed')
                ->latest('id')
                ->first();
            $this->assertNotNull($log);
            $this->assertSame($zipName, $log->properties['file_name'] ?? null);
            $this->assertStringNotContainsString($distinctiveSecret, (string) json_encode($log->toArray()));
        } finally {
            @unlink($sampleQrisFile);
        }
    }

    public function test_backup_verification_failures_delete_partial_files_exit_1_record_backup_failed_and_scrub_secret(): void
    {
        $this->seed(DatabaseSeeder::class);

        $distinctiveSecret = 'SecretLeakCheck#8899!';
        $this->configureMysqlForFakeProcess($distinctiveSecret);

        $scenarios = [
            'non_zero_exit' => [
                'exit' => 2,
                'err' => "mysqldump error with secret {$distinctiveSecret} denied",
                'content' => null,
            ],
            'empty_dump' => [
                'exit' => 0,
                'err' => '',
                'content' => '',
            ],
            'missing_dump_completed' => [
                'exit' => 0,
                'err' => '',
                'content' => str_repeat("-- padding\n", 120)."CREATE TABLE `orders` (`id` int);\n-- truncated",
            ],
            'missing_create_table' => [
                'exit' => 0,
                'err' => '',
                'content' => str_repeat("-- padding\n", 120)."-- Dump completed on 2026-11-27 03:30:00\n",
            ],
        ];

        foreach ($scenarios as $scenarioName => $spec) {
            Process::fake(function (PendingProcess $process) use ($spec): ProcessResult {
                if ($spec['content'] !== null && is_array($process->command)) {
                    foreach ($process->command as $arg) {
                        if (str_starts_with((string) $arg, '--result-file=')) {
                            $targetFile = substr((string) $arg, strlen('--result-file='));
                            file_put_contents($targetFile, $spec['content']);
                        }
                    }
                }

                return Process::result(output: '', errorOutput: $spec['err'], exitCode: $spec['exit']);
            });

            $exitCode = Artisan::call('temupulang:backup');
            $output = Artisan::output();

            $this->assertSame(1, $exitCode, "Scenario {$scenarioName} must exit with 1.");
            $this->assertStringNotContainsString($distinctiveSecret, $output);

            // Partial files must be cleaned up
            $remainingFiles = array_values(array_diff(scandir($this->primaryBackupDir) ?: [], ['.', '..']));
            $this->assertSame([], $remainingFiles, "Scenario {$scenarioName} left partial files behind.");

            $failedLog = ActivityLog::where('module', 'system')
                ->where('action', 'backup.failed')
                ->latest('id')
                ->first();
            $this->assertNotNull($failedLog);
            $this->assertStringNotContainsString($distinctiveSecret, (string) json_encode($failedLog->toArray()));
        }
    }

    public function test_unwritable_copy_path_records_copy_failed_warning_and_still_exits_0_and_refuses_non_mysql_connection(): void
    {
        $this->seed(DatabaseSeeder::class);

        // 1. Non-mysql connection refuses with exit code 1
        config(['database.default' => 'sqlite']);
        $this->assertSame(1, Artisan::call('temupulang:backup'));
        $this->assertStringContainsString("requires the default database connection to be 'mysql'", Artisan::output());

        // 2. Unwritable copy destination (a file path instead of a directory) logs backup.copy_failed warning but exits 0
        $distinctiveSecret = 'CopyWarnSecret#2026!';
        $this->configureMysqlForFakeProcess($distinctiveSecret);

        $blockingFileAsCopyDir = $this->tempRoot.DIRECTORY_SEPARATOR.'not_a_dir.txt';
        file_put_contents($blockingFileAsCopyDir, 'file blocking mkdir');
        config(['backup.copy_path' => $blockingFileAsCopyDir.DIRECTORY_SEPARATOR.'sub']);

        $validDumpContent = str_repeat("-- header\n", 80)
            ."CREATE TABLE `orders` (`id` bigint);\n"
            ."-- Dump completed on 2026-11-27 03:30:00\n";

        Process::fake(function (PendingProcess $process) use ($validDumpContent): ProcessResult {
            if (is_array($process->command)) {
                foreach ($process->command as $arg) {
                    if (str_starts_with((string) $arg, '--result-file=')) {
                        file_put_contents(substr((string) $arg, strlen('--result-file=')), $validDumpContent);
                    }
                }
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        $exitCode = Artisan::call('temupulang:backup');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('[WARN]', $output);

        $this->assertTrue(
            ActivityLog::where('module', 'system')->where('action', 'backup.copy_failed')->exists()
        );
        $this->assertTrue(
            ActivityLog::where('module', 'system')->where('action', 'backup.completed')->exists()
        );
    }

    public function test_backup_verify_creates_and_always_drops_prefixed_temp_database_on_success_and_on_import_failure(): void
    {
        $this->seed(DatabaseSeeder::class);

        $distinctiveSecret = 'VerifyRestoreSecret#2026!';
        $this->configureMysqlForFakeProcess($distinctiveSecret);

        // Create a valid backup zip in primaryBackupDir
        $zipPath = $this->primaryBackupDir.DIRECTORY_SEPARATOR.'temu_pulang_20261127_033000.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString(
            'database.sql',
            "CREATE TABLE `orders` (`id` bigint);\n-- Dump completed on 2026-11-27 03:30:00\n"
        );
        $zip->addFromString('manifest.txt', "Temu Pulang Backup Manifest\n");
        $zip->close();

        // 1. Passing verification run: issues CREATE DATABASE, SOURCE import, stats SELECT, and DROP DATABASE
        $executedSqls = [];
        Process::fake(function (PendingProcess $process) use (&$executedSqls): ProcessResult {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            $executedSqls[] = $cmd;

            if (str_contains($cmd, 'information_schema.tables')) {
                $statsOutput = implode("\n", [
                    'live_tables 28',
                    'restored_tables 28',
                    'live_orders 45',
                    'restored_orders 42',
                    'live_payment_transactions 44',
                    'restored_payment_transactions 42',
                    'live_stock_movements 120',
                    'restored_stock_movements 115',
                ]);

                return Process::result(output: $statsOutput, errorOutput: '', exitCode: 0);
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        $exitCode = Artisan::call('temupulang:backup-verify');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);
        $this->assertStringContainsString('[PASS] Verified backup archive:', $output);
        $this->assertStringContainsString('Tables count — Live: 28 | Restored: 28', $output);
        $this->assertStringContainsString('Row count [orders] — Live: 45 | Restored: 42 (diff: +3)', $output);
        $this->assertStringNotContainsString($distinctiveSecret, $output);

        $allSql = implode("\n", $executedSqls);
        $this->assertMatchesRegularExpression('/CREATE DATABASE `temu_restore_check_[a-z0-9]+`/', $allSql);
        $this->assertMatchesRegularExpression('/DROP DATABASE IF EXISTS `temu_restore_check_[a-z0-9]+`/', $allSql);
        $this->assertTrue(ActivityLog::where('action', 'backup.verified')->exists());

        // 2. Failing import run: import returns exit code 1, yet DROP DATABASE is STILL issued in finally!
        $failedRunSqls = [];
        Process::fake(function (PendingProcess $process) use (&$failedRunSqls, $distinctiveSecret): ProcessResult {
            $cmd = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            $failedRunSqls[] = $cmd;

            if (str_contains($cmd, 'SOURCE ')) {
                return Process::result(
                    output: '',
                    errorOutput: "ERROR 1064 syntax error near line 1 (secret {$distinctiveSecret})",
                    exitCode: 1
                );
            }

            return Process::result(output: '', errorOutput: '', exitCode: 0);
        });

        $failExitCode = Artisan::call('temupulang:backup-verify');
        $failOutput = Artisan::output();

        $this->assertSame(1, $failExitCode);
        $this->assertStringContainsString('[FAIL]', $failOutput);
        $this->assertStringNotContainsString($distinctiveSecret, $failOutput);

        $failedAllSql = implode("\n", $failedRunSqls);
        $this->assertMatchesRegularExpression('/CREATE DATABASE `temu_restore_check_[a-z0-9]+`/', $failedAllSql);
        $this->assertMatchesRegularExpression('/DROP DATABASE IF EXISTS `temu_restore_check_[a-z0-9]+`/', $failedAllSql);

        $verifyFailedLog = ActivityLog::where('action', 'backup.verify_failed')->latest('id')->first();
        $this->assertNotNull($verifyFailedLog);
        $this->assertStringNotContainsString($distinctiveSecret, (string) json_encode($verifyFailedLog->toArray()));
    }

    public function test_preflight_new_backup_checks_pass_warn_and_fail_correctly_without_leaking_secrets(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $realOwnerPass = 'ValidPreflightOwner#2026!';
        User::factory()->owner()->create([
            'name' => 'Real Store Owner',
            'email' => 'owner@kopitemu.id',
            'password' => Hash::make($realOwnerPass),
            'is_active' => true,
        ]);

        ShopSetting::create([
            'name' => 'Temu Pulang',
            'address' => 'Jl. Melawai Raya No. 8, Jakarta Selatan',
            'phone' => '021-5551234',
            'email' => 'contact@kopitemu.id',
            'receipt_footer' => ShopSettingSeeder::GENERIC_RECEIPT_FOOTER,
            'tax_rate' => 0,
            'service_charge_rate' => 0,
            'printer_name' => 'POS-80',
            'is_active' => true,
        ]);

        // Create a dummy executable file to represent mysqldump
        $fakeMysqldumpFile = $this->tempRoot.DIRECTORY_SEPARATOR.'mysqldump.exe';
        file_put_contents($fakeMysqldumpFile, 'binary');

        $this->app['env'] = 'production';
        config([
            'app.debug' => false,
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.demo_login_enabled' => null,
            'session.lifetime' => 720,
            'logging.channels.stack.level' => 'warning',
            'logging.channels.single.level' => 'warning',
            'backup.path' => $this->primaryBackupDir,
            'backup.copy_path' => $this->copyBackupDir,
            'backup.mysqldump_path' => $fakeMysqldumpFile,
            'backup.max_age_hours' => 26,
            'backup.required' => false,
        ]);

        // 1. Never backed up with BACKUP_REQUIRED=false -> WARN and exit code 0
        $this->assertSame(0, Artisan::call('temupulang:preflight'));
        $outWarn = Artisan::output();
        $this->assertStringContainsString('[WARN] No completed database backup (backup.completed) found', $outWarn);
        $this->assertStringContainsString('[PASS] PHP zip extension is loaded.', $outWarn);
        $this->assertStringContainsString('[PASS] mysqldump binary is discoverable.', $outWarn);
        $this->assertStringNotContainsString($realOwnerPass, $outWarn);

        // 2. Never backed up with BACKUP_REQUIRED=true in production -> FAIL and exit code 1
        config(['backup.required' => true]);
        $this->assertSame(1, Artisan::call('temupulang:preflight'));
        $outFail = Artisan::output();
        $this->assertStringContainsString('[FAIL] No completed database backup (backup.completed) found while BACKUP_REQUIRED is enabled in production.', $outFail);

        // 3. Old backup (> 26 hours) even with BACKUP_REQUIRED=true -> WARN and exit code 0
        $oldLog = ActivityLog::record(
            action: 'backup.completed',
            description: 'Old backup',
            properties: ['file_name' => 'temu_pulang_20261125_033000.zip', 'size_bytes' => 20480],
            module: 'system',
        );
        $oldLog->forceFill(['created_at' => CarbonImmutable::now()->subHours(30)])->save();

        $this->assertSame(0, Artisan::call('temupulang:preflight'));
        $outOld = Artisan::output();
        $this->assertStringContainsString('[WARN] Latest completed backup is 30 hours old', $outOld);

        // 4. Recent backup (2 hours ago) -> PASS and exit code 0
        $recentLog = ActivityLog::record(
            action: 'backup.completed',
            description: 'Recent backup',
            properties: ['file_name' => 'temu_pulang_20261127_033000.zip', 'size_bytes' => 20480],
            module: 'system',
        );
        $recentLog->forceFill(['created_at' => CarbonImmutable::now()->subHours(2)])->save();

        $this->assertSame(0, Artisan::call('temupulang:preflight'));
        $outRecent = Artisan::output();
        $this->assertStringContainsString('[PASS] Latest completed backup is 2 hour(s) old', $outRecent);

        // 5. Same-disk warning (BACKUP_PATH inside base_path and BACKUP_COPY_PATH empty) & missing mysqldump warning
        config([
            'backup.path' => storage_path('app/backups'),
            'backup.copy_path' => '',
            'backup.mysqldump_path' => $this->tempRoot.DIRECTORY_SEPARATOR.'nonexistent_mysqldump.exe',
        ]);
        $this->assertSame(0, Artisan::call('temupulang:preflight'));
        $outSameDisk = Artisan::output();
        $this->assertStringContainsString('[WARN] BACKUP_PATH is inside the project directory and BACKUP_COPY_PATH is empty', $outSameDisk);
        $this->assertStringContainsString('[WARN] mysqldump binary could not be located', $outSameDisk);

        // 6. Missing zip extension -> FAIL and exit code 1
        config(['backup.preflight_zip_loaded' => false]);
        $this->assertSame(1, Artisan::call('temupulang:preflight'));
        $outNoZip = Artisan::output();
        $this->assertStringContainsString('[FAIL] PHP zip extension is not loaded.', $outNoZip);
        config(['backup.preflight_zip_loaded' => null]);
    }

    public function test_owner_dashboard_backup_banner_states_and_localization_and_rbac(): void
    {
        $this->seed(DatabaseSeeder::class);

        $owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();

        config(['backup.max_age_hours' => 26]); // half is 13 hours

        // 1. Never backed up -> red banner (danger)
        App::setLocale('en');
        Livewire::actingAs($owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee(__('messages.owner_dashboard.backup_never_run'))
            ->assertSee('bg-rose-50');

        // 2. Backup completed 30 hours ago (>= 26h) -> red overdue banner
        $completedLog = ActivityLog::record(
            action: 'backup.completed',
            description: 'Completed backup',
            properties: ['file_name' => 'temu_pulang_20261126_033000.zip', 'size_bytes' => 51200],
            module: 'system',
        );
        $completedLog->forceFill(['created_at' => CarbonImmutable::now()->subHours(30)])->save();

        Livewire::actingAs($owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('Database Backup Overdue:')
            ->assertSee('50 KB')
            ->assertSee('bg-rose-50');

        // 3. Backup completed 15 hours ago (>= 13h and < 26h) -> amber aging banner
        $completedLog->forceFill(['created_at' => CarbonImmutable::now()->subHours(15)])->save();

        Livewire::actingAs($owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('15 hours ago')
            ->assertSee('bg-amber-50');

        // 4. Backup completed 2 hours ago (< 13h) -> neutral healthy line + Indonesian locale check
        $completedLog->forceFill(['created_at' => CarbonImmutable::now()->subHours(2)])->save();

        Livewire::actingAs($owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('Last backup:')
            ->assertSee('50 KB')
            ->assertDontSee('Warning: Most recent backup attempt failed');

        // 5. Newer backup.failed entry -> shows failure notice alongside last backup
        $failedLog = ActivityLog::record(
            action: 'backup.failed',
            description: 'Failed backup',
            properties: ['error' => 'Disk full'],
            module: 'system',
        );
        $failedLog->forceFill(['created_at' => CarbonImmutable::now()->subHour()])->save();

        Livewire::actingAs($owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('Last backup:')
            ->assertSee('Warning: Most recent backup attempt failed at');

        // Indonesian locale check
        App::setLocale('id');
        Livewire::actingAs($owner)
            ->test(OwnerDashboard::class)
            ->assertStatus(200)
            ->assertSee('Cadangan terakhir:')
            ->assertSee('Peringatan: Percobaan backup terakhir gagal pada')
            ->assertDontSee('backup.completed')
            ->assertDontSee('backup.failed');

        // 6. Cashier cannot access OwnerDashboard
        Livewire::actingAs($cashier)
            ->test(OwnerDashboard::class)
            ->assertForbidden();

        // 7. Language key parity check
        $idKeys = array_keys(Arr::dot(require base_path('lang/id/messages.php')));
        $enKeys = array_keys(Arr::dot(require base_path('lang/en/messages.php')));
        sort($idKeys);
        sort($enKeys);
        $this->assertSame($idKeys, $enKeys);
    }

    private function configureMysqlForFakeProcess(string $secret): void
    {
        // Share the in-memory SQLite PDO with the 'mysql' connection so ActivityLog::record still writes to SQLite
        // while config('database.default') is set to 'mysql' for BackupService.
        $sqliteConnection = $this->app['db']->connection('sqlite');
        $this->app['db']->extend('mysql', static fn () => $sqliteConnection);

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host' => '127.0.0.1',
            'database.connections.mysql.port' => '3306',
            'database.connections.mysql.database' => 'temu_pulang',
            'database.connections.mysql.username' => 'temupulang_user',
            'database.connections.mysql.password' => $secret,
            'backup.path' => $this->primaryBackupDir,
            'backup.copy_path' => $this->copyBackupDir,
            'backup.retention_days' => 14,
            'backup.mysqldump_path' => 'mysqldump',
            'backup.mysql_client_path' => 'mysql',
            'backup.min_dump_bytes' => 100,
        ]);
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

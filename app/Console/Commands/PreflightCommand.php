<?php

namespace App\Console\Commands;

use App\Http\Controllers\AuthController;
use App\Models\ActivityLog;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\BackupService;
use Carbon\CarbonImmutable;
use Database\Seeders\ShopSettingSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Throwable;

class PreflightCommand extends Command
{
    protected $signature = 'temupulang:preflight';

    protected $description = 'Run read-only production preflight checks before go-live.';

    /**
     * Demo emails created by UserSeeder.
     *
     * @var list<string>
     */
    private const DEMO_EMAILS = [
        'owner@temupulang.local',
        'cashier@temupulang.local',
    ];

    /**
     * Demo passwords used by UserSeeder (checked via Hash::check, never printed).
     *
     * @var list<string>
     */
    private const DEMO_PASSWORDS = [
        'TemuPulang@2026!',
        'Cashier@2026!',
    ];

    public function handle(): int
    {
        $passCount = 0;
        $warnCount = 0;
        $failCount = 0;
        $remediations = [];

        $record = function (string $status, string $label, ?string $remediation = null) use (&$passCount, &$warnCount, &$failCount, &$remediations): void {
            if ($status === 'PASS') {
                $passCount++;
                $this->line("[PASS] {$label}");

                return;
            }

            if ($status === 'WARN') {
                $warnCount++;
                $this->warn("[WARN] {$label}");
                if ($remediation !== null) {
                    $remediations[] = $remediation;
                }

                return;
            }

            $failCount++;
            $this->error("[FAIL] {$label}");
            if ($remediation !== null) {
                $remediations[] = $remediation;
            }
        };

        $env = (string) app()->environment();
        $isProduction = $env === 'production';

        // 1. APP_ENV is production (WARN otherwise)
        if ($isProduction) {
            $record('PASS', "APP_ENV is 'production'.");
        } else {
            $record('WARN', "APP_ENV is '{$env}' (expected 'production' for go-live).", 'Set APP_ENV=production in .env and run: php artisan config:clear');
        }

        // 2. APP_DEBUG is false (FAIL if true in production)
        $debug = (bool) config('app.debug');
        if (! $debug) {
            $record('PASS', 'APP_DEBUG is false.');
        } elseif ($isProduction) {
            $record('FAIL', 'APP_DEBUG is true while APP_ENV is production.', 'Set APP_DEBUG=false in .env and run: php artisan config:clear');
        } else {
            $record('WARN', "APP_DEBUG is true in '{$env}' environment.", 'Set APP_DEBUG=false in .env before go-live.');
        }

        // 3. APP_KEY is set (FAIL)
        $appKey = trim((string) config('app.key'));
        if ($appKey !== '') {
            $record('PASS', 'APP_KEY is set.');
        } else {
            $record('FAIL', 'APP_KEY is missing or empty.', 'Run: php artisan key:generate');
        }

        // 4. Database connection works (FAIL)
        $dbConnected = false;
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');
            $dbConnected = true;
            $record('PASS', 'Database connection is healthy.');
        } catch (Throwable $e) {
            $record('FAIL', 'Database connection failed: '.$e->getMessage(), 'Check DB_* settings in .env and run: php artisan migrate --force');
        }

        // 5. storage and bootstrap/cache are writable (FAIL)
        $storagePath = storage_path();
        $bootstrapCachePath = base_path('bootstrap/cache');
        if (is_dir($storagePath) && is_writable($storagePath) && is_dir($bootstrapCachePath) && is_writable($bootstrapCachePath)) {
            $record('PASS', 'storage/ and bootstrap/cache/ are writable.');
        } else {
            $record('FAIL', 'storage/ or bootstrap/cache/ is not writable.', 'Grant write permissions to storage/ and bootstrap/cache/.');
        }

        // 6. public/storage link exists (WARN)
        if (file_exists(public_path('storage'))) {
            $record('PASS', 'public/storage link exists.');
        } else {
            $record('WARN', 'public/storage link is missing.', 'Run: php artisan storage:link');
        }

        // 7. Demo login is disabled in the current environment (FAIL if enabled while APP_ENV=production)
        $demoEnabled = AuthController::isDemoLoginEnabled();
        if (! $demoEnabled) {
            $record('PASS', 'Quick demo login is disabled.');
        } elseif ($isProduction) {
            $record('FAIL', 'Quick demo login is enabled while APP_ENV is production.', 'Disable demo login in production and run: php artisan config:clear');
        } else {
            $record('WARN', "Quick demo login is enabled in '{$env}' environment.", 'Set APP_ENV=production to disable quick demo login.');
        }

        // 8. At least one ACTIVE owner exists (FAIL)
        if ($dbConnected) {
            try {
                $activeOwnerCount = User::query()
                    ->where('is_active', true)
                    ->whereHas('roles', fn ($q) => $q->where('slug', 'owner'))
                    ->count();

                if ($activeOwnerCount >= 1) {
                    $record('PASS', "Found {$activeOwnerCount} active owner account(s).");
                } else {
                    $record('FAIL', 'No active owner account found.', 'Run: php artisan temupulang:create-user --role=owner');
                }
            } catch (Throwable $e) {
                $record('FAIL', 'Could not verify active owner accounts: '.$e->getMessage(), 'Run: php artisan migrate --force');
            }
        } else {
            $record('FAIL', 'Cannot check active owner accounts without database connection.', 'Fix database connection first.');
        }

        // 9. No active user still has an email or password from the demo seeders (FAIL)
        if ($dbConnected) {
            try {
                $demoEmails = array_values(array_unique(array_filter([
                    ...self::DEMO_EMAILS,
                    strtolower(trim((string) env('SEED_OWNER_EMAIL', ''))),
                    strtolower(trim((string) env('SEED_CASHIER_EMAIL', ''))),
                ])));

                $demoPasswords = array_values(array_unique(array_filter([
                    ...self::DEMO_PASSWORDS,
                    (string) env('SEED_OWNER_PASSWORD', ''),
                    (string) env('SEED_CASHIER_PASSWORD', ''),
                ])));

                $flaggedEmails = [];
                $activeUsers = User::query()->where('is_active', true)->get();

                foreach ($activeUsers as $activeUser) {
                    $emailLower = strtolower((string) $activeUser->email);
                    $hasDemoEmail = in_array($emailLower, $demoEmails, true);
                    $hasDemoPassword = false;

                    foreach ($demoPasswords as $candidate) {
                        if ($candidate !== '' && Hash::check($candidate, (string) $activeUser->password)) {
                            $hasDemoPassword = true;
                            break;
                        }
                    }

                    if ($hasDemoEmail || $hasDemoPassword) {
                        $flaggedEmails[] = $activeUser->email;
                    }
                }

                if ($flaggedEmails === []) {
                    $record('PASS', 'No active user account uses a demo seeder email or demo seeder password.');
                } else {
                    $list = implode(', ', $flaggedEmails);
                    $record(
                        'FAIL',
                        "Active user account(s) still use a demo seeder email or demo seeder password: {$list}",
                        'Run: php artisan temupulang:deactivate-user <email> or php artisan temupulang:set-password <email>'
                    );
                }
            } catch (Throwable $e) {
                $record('FAIL', 'Could not verify demo credentials on active accounts: '.$e->getMessage());
            }
        } else {
            $record('FAIL', 'Cannot check active user demo credentials without database connection.');
        }

        // 10. Shop settings row exists (WARN)
        $shopSetting = null;
        if ($dbConnected) {
            try {
                $shopSetting = ShopSetting::current();
            } catch (Throwable) {
                $shopSetting = null;
            }
        }

        if ($shopSetting !== null) {
            $record('PASS', "Active shop_settings row exists ({$shopSetting->name}).");
        } else {
            $record('WARN', 'No active shop_settings row exists.', 'Run: php artisan db:seed --class=ShopSettingSeeder');
        }

        // 10b. Shop settings sample placeholder check (FAIL) & empty address/phone check (WARN)
        if ($shopSetting !== null) {
            $address = trim((string) ($shopSetting->address ?? ''));
            $phone = trim((string) ($shopSetting->phone ?? ''));
            $email = trim((string) ($shopSetting->email ?? ''));
            $footer = trim((string) ($shopSetting->receipt_footer ?? ''));

            $placeholderFields = [];
            if ($address === ShopSettingSeeder::SAMPLE_ADDRESS) {
                $placeholderFields[] = 'address';
            }
            if ($phone === ShopSettingSeeder::SAMPLE_PHONE) {
                $placeholderFields[] = 'phone';
            }
            if ($email === ShopSettingSeeder::SAMPLE_EMAIL) {
                $placeholderFields[] = 'email';
            }
            if ($footer === ShopSettingSeeder::SAMPLE_RECEIPT_FOOTER || str_contains($footer, 'Pass: kopienak')) {
                $placeholderFields[] = 'receipt_footer';
            }

            if ($placeholderFields !== []) {
                $fieldsList = implode(', ', $placeholderFields);
                $record(
                    'FAIL',
                    "shop_settings still contains sample seeder placeholder value(s) in: {$fieldsList}.",
                    'Open Pengaturan Toko (/settings) and replace the sample address, phone, email, and receipt footer.'
                );
            } else {
                $record('PASS', 'shop_settings does not contain sample seeder placeholder values.');
            }

            if ($address === '' || $phone === '') {
                $record(
                    'WARN',
                    'Shop address or phone number is empty in shop_settings.',
                    'Open Pengaturan Toko (/settings) and fill in the store address and phone number.'
                );
            } else {
                $record('PASS', 'Shop address and phone number are populated.');
            }
        }

        // 11. Configured receipt printer destination is set (WARN)
        $printerName = trim((string) ($shopSetting?->printer_name ?? ''));
        if ($printerName !== '') {
            $record('PASS', "Receipt printer destination is configured ({$printerName}).");
        } else {
            $record('WARN', 'Receipt printer destination (printer_name) is not set.', 'Configure the thermal printer name in Owner Shop Settings (/settings) and test with: php artisan print:test');
        }

        // 12. Cache/queue/session drivers (WARN if 'array')
        $cacheDriver = (string) config('cache.default');
        $queueDriver = (string) config('queue.default');
        $sessionDriver = (string) config('session.driver');

        if (in_array('array', [$cacheDriver, $queueDriver, $sessionDriver], true)) {
            $record(
                'WARN',
                "Non-persistent 'array' driver detected (cache={$cacheDriver}, queue={$queueDriver}, session={$sessionDriver}).",
                'Set CACHE_STORE=database, QUEUE_CONNECTION=database, and SESSION_DRIVER=database in .env'
            );
        } else {
            $record('PASS', "Cache ({$cacheDriver}), queue ({$queueDriver}), and session ({$sessionDriver}) drivers are persistent.");
        }

        // 13. SESSION_LIFETIME is at least 480 minutes (WARN if below 480)
        $sessionLifetime = (int) config('session.lifetime', 120);
        if ($sessionLifetime < 480) {
            $record(
                'WARN',
                "SESSION_LIFETIME is {$sessionLifetime} minutes (below recommended 480 minutes for a full cashier shift).",
                'Set SESSION_LIFETIME=720 in .env and run: php artisan config:clear'
            );
        } else {
            $record('PASS', "SESSION_LIFETIME is {$sessionLifetime} minutes (>= 480).");
        }

        // 14. LOG_LEVEL is not debug while APP_ENV is production (WARN)
        $defaultChannel = (string) config('logging.default', 'stack');
        $configuredLevel = config("logging.channels.{$defaultChannel}.level")
            ?? config('logging.channels.single.level')
            ?? env('LOG_LEVEL', 'debug');
        $logLevel = strtolower(trim((string) $configuredLevel));

        if ($isProduction && $logLevel === 'debug') {
            $record(
                'WARN',
                "LOG_LEVEL is 'debug' while APP_ENV is 'production'.",
                'Set LOG_LEVEL=warning in .env and run: php artisan config:clear'
            );
        } else {
            $record('PASS', "LOG_LEVEL is '{$logLevel}'.");
        }

        // 15. PHP zip extension is loaded (FAIL if missing)
        $zipLoaded = (bool) config('backup.preflight_zip_loaded', extension_loaded('zip'));
        if ($zipLoaded) {
            $record('PASS', 'PHP zip extension is loaded.');
        } else {
            $record(
                'FAIL',
                'PHP zip extension is not loaded.',
                'Enable extension=zip in php.ini and restart Apache / PHP.'
            );
        }

        // 16. mysqldump binary can be found (WARN if not found)
        $mysqldumpPath = (string) config('backup.mysqldump_path', 'mysqldump');
        if (BackupService::isBinaryDiscoverable($mysqldumpPath)) {
            $record('PASS', 'mysqldump binary is discoverable.');
        } else {
            $record(
                'WARN',
                'mysqldump binary could not be located at configured MYSQLDUMP_PATH or on system PATH.',
                'Set MYSQLDUMP_PATH="C:\xampp\mysql\bin\mysqldump.exe" in .env and run: php artisan config:clear'
            );
        }

        // 17. BACKUP_PATH inside project folder while BACKUP_COPY_PATH is empty (WARN)
        $backupPathRaw = trim((string) config('backup.path', storage_path('app/backups')));
        $copyPathRaw = trim((string) config('backup.copy_path', ''));
        $normalizedBackupPath = strtolower(str_replace('\\', '/', rtrim($backupPathRaw, '\\/')));
        $normalizedBasePath = strtolower(str_replace('\\', '/', rtrim(base_path(), '\\/')));
        $isInsideProject = $normalizedBackupPath === $normalizedBasePath
            || str_starts_with($normalizedBackupPath, $normalizedBasePath.'/');

        if ($isInsideProject && $copyPathRaw === '') {
            $record(
                'WARN',
                'BACKUP_PATH is inside the project directory and BACKUP_COPY_PATH is empty (backups are stored on the same disk as the application data).',
                'Set BACKUP_COPY_PATH in .env to a folder on a separate physical drive or external storage.'
            );
        } else {
            $record('PASS', 'Backup storage destination is separated from the project directory or has a secondary copy path configured.');
        }

        // 18. Recent completed database backup check (FAIL if BACKUP_REQUIRED=true in production and never backed up; WARN if never backed up otherwise or older than BACKUP_MAX_AGE_HOURS)
        $maxAgeHours = max(1, (int) config('backup.max_age_hours', 26));
        $backupRequired = (bool) config('backup.required', false);

        if ($dbConnected) {
            try {
                $latestBackup = ActivityLog::query()
                    ->where('module', 'system')
                    ->where('action', 'backup.completed')
                    ->orderByDesc('created_at')
                    ->orderByDesc('id')
                    ->first();

                if ($latestBackup === null || $latestBackup->created_at === null) {
                    if ($isProduction && $backupRequired) {
                        $record(
                            'FAIL',
                            'No completed database backup (backup.completed) found while BACKUP_REQUIRED is enabled in production.',
                            'Run: php artisan temupulang:backup (this check clears automatically after the first successful backup).'
                        );
                    } else {
                        $record(
                            'WARN',
                            'No completed database backup (backup.completed) found in activity_logs yet.',
                            'Set BACKUP_REQUIRED=true on the cafe host PC and run: php artisan temupulang:backup'
                        );
                    }
                } else {
                    $completedAt = CarbonImmutable::instance($latestBackup->created_at);
                    $ageSeconds = max(0, CarbonImmutable::now()->getTimestamp() - $completedAt->getTimestamp());
                    $ageHours = intdiv($ageSeconds, 3600);

                    if ($ageHours >= $maxAgeHours) {
                        $record(
                            'WARN',
                            "Latest completed backup is {$ageHours} hours old (exceeds BACKUP_MAX_AGE_HOURS={$maxAgeHours}h).",
                            'Run: php artisan temupulang:backup and verify the Windows Task Scheduler nightly backup task.'
                        );
                    } else {
                        $record('PASS', "Latest completed backup is {$ageHours} hour(s) old (within {$maxAgeHours}h limit).");
                    }
                }
            } catch (Throwable) {
                $record('WARN', 'Could not inspect activity_logs for backup history.');
            }
        }

        $this->newLine();
        $this->line("Preflight summary: {$passCount} PASS, {$warnCount} WARN, {$failCount} FAIL.");

        if ($remediations !== []) {
            $this->newLine();
            $this->line('Recommended remediation commands / actions:');
            foreach (array_values(array_unique($remediations)) as $cmd) {
                $this->line("  - {$cmd}");
            }
        }

        return $failCount > 0 ? self::FAILURE : self::SUCCESS;
    }
}

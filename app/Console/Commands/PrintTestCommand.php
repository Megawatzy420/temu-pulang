<?php

namespace App\Console\Commands;

use App\Services\Printing\EscPosBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Sends a simple ESC/POS test page to the configured printer.
 * Does NOT create any database records.
 */
class PrintTestCommand extends Command
{
    protected $signature = 'print:test
                            {--printer=POS80 Printer : Windows printer queue name}
                            {--timeout=10 : Seconds to wait for tp-rawprint.exe}';

    protected $description = 'Send a test page to the thermal printer. No database records created.';

    private const HELPER_PATH = 'storage/bin/tp-rawprint.exe';

    public function handle(): int
    {
        $printerName = $this->option('printer');
        $timeout = (float) $this->option('timeout');

        $this->info("Sending test page to printer: \"{$printerName}\"");

        // Build test payload
        $esc = new EscPosBuilder;
        $payload = $esc
            ->initialize()
            ->alignCenter()
            ->bold(true)
            ->text('=== TES CETAK / PRINT TEST ===')
            ->bold(false)
            ->text('Temu Pulang POS')
            ->alignLeft()
            ->rule(48)
            ->twoCol('Printer:', $printerName, 48)
            ->twoCol('Waktu:', now('Asia/Jakarta')->format('d/m/Y H:i:s'), 48)
            ->twoCol('Versi:', 'Task 5.5 v1.0', 48)
            ->rule(48)
            ->alignCenter()
            ->text('Jika teks ini tercetak dengan benar,')
            ->text('koneksi printer berfungsi normal.')
            ->alignLeft()
            ->feed(5)
            ->getBytes();

        // Write to temp file
        $tempDir = base_path('storage/app/prints');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $tempFile = $tempDir.DIRECTORY_SEPARATOR.'test_'.Str::ulid().'.bin';
        file_put_contents($tempFile, $payload);

        $helperPath = base_path(self::HELPER_PATH);

        if (! file_exists($helperPath)) {
            $this->error("Helper not found: {$helperPath}");
            @unlink($tempFile);

            return self::FAILURE;
        }

        // Execute helper
        $process = new Process(
            [$helperPath, '--printer='.$printerName, '--file='.$tempFile],
            null,
            null,
            null,
            $timeout
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->error("Helper timed out after {$timeout}s.");
            @unlink($tempFile);

            return self::FAILURE;
        } finally {
            @unlink($tempFile);
        }

        $exitCode = $process->getExitCode();

        if ($process->getOutput()) {
            $this->line('[helper] '.trim($process->getOutput()));
        }
        if ($process->getErrorOutput()) {
            $this->error('[helper] '.trim($process->getErrorOutput()));
        }

        if ($exitCode === 0) {
            $this->info('Test page successfully sent to spooler.');

            return self::SUCCESS;
        }

        $this->error("Helper exited with code {$exitCode}. Test page may not have printed.");

        return self::FAILURE;
    }
}

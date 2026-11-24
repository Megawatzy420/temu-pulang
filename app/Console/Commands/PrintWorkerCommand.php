<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\PrintJob;
use App\Models\ShopSetting;
use App\Services\Printing\KitchenTicketRendererService;
use App\Services\Printing\ReceiptRendererService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Print worker: polls the print_jobs table and dispatches jobs one at a time.
 *
 * Singleton enforcement: configure Windows Task Scheduler with
 * "If the task is already running, do not start a new instance."
 *
 * Startup recovery:
 *   Before processing new jobs, the worker inspects existing `processing`
 *   jobs and waits for them to become stale (>60s), then marks them
 *   `uncertain`. This prevents ghost workers from corrupting state.
 */
class PrintWorkerCommand extends Command
{
    protected $signature = 'print:work
                            {--timeout=10 : Seconds to wait for tp-rawprint.exe before killing it}
                            {--sleep=2 : Seconds to sleep between polls when queue is empty}
                            {--stale-threshold=60 : Seconds before a processing job is considered stale}';

    protected $description = 'Start the print job worker (single instance via Task Scheduler).';

    private const HELPER_PATH = 'storage/bin/tp-rawprint.exe';

    private const PRINTS_DIR = 'storage/app/prints';

    public function __construct(
        protected ReceiptRendererService $receiptRenderer,
        protected KitchenTicketRendererService $kitchenRenderer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $staleThreshold = (int) $this->option('stale-threshold');
        $timeout = (float) $this->option('timeout');
        $sleep = (int) $this->option('sleep');

        $this->info('[print:work] Worker starting. Stale threshold: '.$staleThreshold.'s');

        // ── Startup crash recovery ────────────────────────────────────────────
        $this->runStartupRecovery($staleThreshold);

        $this->info('[print:work] Recovery complete. Entering poll loop.');

        // ── Main poll loop ────────────────────────────────────────────────────
        while (true) {
            $job = $this->claimNextJob();

            if ($job === null) {
                sleep($sleep);

                continue;
            }

            $this->processJob($job, $timeout);
        }

        return self::SUCCESS;
    }

    // ── Startup recovery ──────────────────────────────────────────────────────

    /**
     * Before claiming any new jobs, recover stale `processing` jobs.
     * Blocks until all processing jobs are old enough to be safely recovered.
     */
    private function runStartupRecovery(int $staleThreshold): void
    {
        $this->info('[recovery] Checking for stale processing jobs...');

        while (true) {
            $job = PrintJob::where('status', 'processing')
                ->whereNotNull('locked_at')
                ->oldest('locked_at')
                ->first();

            if ($job === null) {
                break;
            }

            $ageSeconds = abs((int) now()->diffInSeconds($job->locked_at));

            if ($ageSeconds < $staleThreshold) {
                $waitFor = $staleThreshold - $ageSeconds + 1;
                $this->warn("[recovery] Job #{$job->id} is only {$ageSeconds}s old. Waiting {$waitFor}s for it to become stale...");
                sleep($waitFor);

                continue; // Re-evaluate after wait
            }

            // Mark as uncertain — something interrupted the previous worker
            $affected = DB::table('print_jobs')
                ->where('id', $job->id)
                ->where('status', 'processing')
                ->update([
                    'status' => 'uncertain',
                    'claim_token' => null,
                    'locked_at' => null,
                    'last_error' => 'Worker crash recovery: job was left in processing state. Owner inspection required.',
                    'updated_at' => now('Asia/Jakarta'),
                ]);

            if ($affected > 0) {
                $this->warn("[recovery] Marked job #{$job->id} as uncertain (stale processing job).");
            } else {
                // If update didn't match (already updated by another thread), break to prevent loop
                break;
            }
            // Loop again — there may be more stale jobs
        }
    }

    // ── Job claiming ──────────────────────────────────────────────────────────

    private function claimNextJob(): ?PrintJob
    {
        return DB::transaction(function () {
            $job = PrintJob::where('status', 'pending')
                ->oldest()
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            $claimToken = (string) Str::uuid();

            DB::table('print_jobs')
                ->where('id', $job->id)
                ->where('status', 'pending')
                ->update([
                    'status' => 'processing',
                    'claim_token' => $claimToken,
                    'locked_at' => now('Asia/Jakarta'),
                    'attempts' => $job->attempts + 1,
                    'updated_at' => now('Asia/Jakarta'),
                ]);

            return $job->fresh();
        });
    }

    // ── Job processing ────────────────────────────────────────────────────────

    private function processJob(PrintJob $job, float $timeout): void
    {
        $this->info("[worker] Processing job #{$job->id} (type={$job->job_type}, order={$job->order_id})");

        $payloadPath = null;

        try {
            // Load necessary relations
            $order = $job->order()->with(['items.modifiers'])->firstOrFail();

            // Render ESC/POS payload
            $payload = $this->renderPayload($job, $order);

            // Write to unique temp file
            $payloadPath = base_path(self::PRINTS_DIR).DIRECTORY_SEPARATOR.'print_'.Str::ulid().'_'.time().'.bin';
            if (! is_dir(dirname($payloadPath))) {
                mkdir(dirname($payloadPath), 0755, true);
            }
            file_put_contents($payloadPath, $payload);

            // Execute helper
            $printerName = $job->printer_destination ?? 'POS80 Printer';
            $exitCode = $this->executeHelper($printerName, $payloadPath, $timeout);

            // Map exit code → status
            $this->applyExitCode($job, $exitCode);

        } catch (\Throwable $e) {
            $this->error("[worker] Exception processing job #{$job->id}: ".$e->getMessage());
            $this->fencedUpdate($job, 'failed', $e->getMessage());
        } finally {
            // Clean up temp file
            if ($payloadPath !== null && file_exists($payloadPath)) {
                @unlink($payloadPath);
            }
        }
    }

    private function renderPayload(PrintJob $job, Order $order): string
    {
        if ($job->job_type === 'customer_receipt') {
            $transaction = $order->paymentTransaction()->with('payments')->firstOrFail();
            $setting = ShopSetting::current();
            if ($setting === null) {
                throw new \RuntimeException('Shop settings not found.');
            }

            return $this->receiptRenderer->render($order, $transaction, $setting, (bool) $job->is_reprint);
        }

        if ($job->job_type === 'kitchen_ticket') {
            return $this->kitchenRenderer->render($order);
        }

        throw new \RuntimeException("Unknown job_type: {$job->job_type}");
    }

    private function executeHelper(string $printerName, string $payloadPath, float $timeout): int
    {
        $helperPath = base_path(self::HELPER_PATH);

        $process = new Process(
            [$helperPath, '--printer='.$printerName, '--file='.$payloadPath],
            null,
            null,
            null,
            $timeout
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $this->error('[worker] Helper process timed out after '.$timeout.'s');

            return 99; // Map timeout → uncertain
        }

        $exitCode = $process->getExitCode();

        if ($process->getOutput()) {
            $this->line('[helper] '.trim($process->getOutput()));
        }
        if ($process->getErrorOutput()) {
            $this->error('[helper] '.trim($process->getErrorOutput()));
        }

        return $exitCode ?? 7;
    }

    private function applyExitCode(PrintJob $job, int $exitCode): void
    {
        switch ($exitCode) {
            case 0:
                // All bytes written + EndDocPrinter succeeded
                $this->fencedUpdate($job, 'completed', null, now('Asia/Jakarta'));
                $this->info("[worker] Job #{$job->id} completed.");
                break;

            case 1: // Invalid args
            case 2: // File missing
            case 3: // OpenPrinter failed
            case 4: // StartDoc/StartPage failed
                // Safe to retry — no data was submitted to the printer
                $this->fencedUpdate($job, 'failed', "Helper exited with code {$exitCode} (safe failure — no data submitted).");
                $this->warn("[worker] Job #{$job->id} failed safely (exit {$exitCode}). Can be retried.");
                break;

            case 5: // WritePrinter partial write
            case 6: // EndPage/EndDoc failed after bytes written
            case 7: // Unhandled exception after OpenPrinter
            case 99: // Timeout
            default:
                // Ambiguous — data may or may not have reached the printer
                $reason = match ($exitCode) {
                    5 => 'WritePrinter partial write or error mid-stream.',
                    6 => 'EndPage/EndDoc failed after bytes were written.',
                    7 => 'Unhandled exception after printer was opened.',
                    99 => 'Helper process timed out; partial data may have been submitted.',
                    default => "Helper exited with unexpected code {$exitCode}.",
                };
                $this->fencedUpdate($job, 'uncertain', $reason);
                $this->warn("[worker] Job #{$job->id} is uncertain (exit {$exitCode}). Owner inspection required.");
                break;
        }
    }

    // ── Claim-token fenced update ─────────────────────────────────────────────

    private function fencedUpdate(
        PrintJob $job,
        string $status,
        ?string $lastError,
        ?Carbon $completedAt = null
    ): void {
        $update = [
            'status' => $status,
            'claim_token' => null,
            'locked_at' => null,
            'last_error' => $lastError,
            'updated_at' => now('Asia/Jakarta'),
        ];

        if ($status === 'completed') {
            $update['completed_at'] = $completedAt ?? now('Asia/Jakarta');
        }

        $affected = DB::table('print_jobs')
            ->where('id', $job->id)
            ->where('claim_token', $job->claim_token)
            ->update($update);

        if ($affected === 0) {
            $this->warn("[worker] Fenced update for job #{$job->id} matched 0 rows — ownership may have been revoked by recovery.");
        }
    }
}

<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\PrintJob;
use App\Models\ShopSetting;
use App\Models\User;
use App\Services\Printing\KitchenTicketRendererService;
use App\Services\Printing\ReceiptRendererService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages the lifecycle of print jobs.
 *
 * Responsibilities:
 * - Enqueue customer receipt and kitchen ticket jobs after payment
 * - Enqueue explicit reprints (with SALINAN watermark)
 * - Allow manual retry of safe-failure jobs
 * - Allow owner resolution of uncertain jobs
 */
class PrintService
{
    public function __construct(
        protected ReceiptRendererService $receiptRenderer,
        protected KitchenTicketRendererService $kitchenRenderer,
    ) {}

    // ── Job enqueueing ────────────────────────────────────────────────────────

    /**
     * Enqueue a customer receipt job after successful payment.
     * If kitchen printing is enabled, also enqueue a kitchen ticket.
     * Must be called inside an existing DB::transaction (from PaymentService).
     */
    public function queueOrderReceipt(
        Order $order,
        PaymentTransaction $transaction,
        ShopSetting $setting
    ): void {
        if (! $setting->printer_enabled) {
            return;
        }

        $printerName = $setting->printer_name ?? 'POS80 Printer';
        $paperWidth = (int) ($setting->printer_paper_width ?? 80);

        PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => $printerName,
            'paper_width' => $paperWidth,
            'is_reprint' => false,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        if ($setting->kitchen_printer_enabled) {
            $kitchenPrinter = $setting->kitchen_printer_name ?? $printerName;

            PrintJob::create([
                'ulid' => (string) Str::ulid(),
                'order_id' => $order->id,
                'job_type' => 'kitchen_ticket',
                'printer_destination' => $kitchenPrinter,
                'paper_width' => $paperWidth,
                'is_reprint' => false,
                'status' => 'pending',
                'attempts' => 0,
                'max_attempts' => 3,
            ]);
        }
    }

    /**
     * Enqueue an explicit reprint of an existing print job.
     * Creates a new job flagged as a reprint; never modifies the original.
     */
    public function queueReprint(PrintJob $originalJob, User $requestedBy): PrintJob
    {
        $reprint = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $originalJob->order_id,
            'original_print_job_id' => $originalJob->id,
            'job_type' => $originalJob->job_type,
            'printer_destination' => $originalJob->printer_destination,
            'paper_width' => $originalJob->paper_width,
            'is_reprint' => true,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        ActivityLog::record(
            action: 'print.reprint_queued',
            description: "Cetak ulang (reprint) diminta untuk pesanan #{$originalJob->order_id} oleh {$requestedBy->name}.",
            subject: $reprint,
            user: $requestedBy,
            module: 'printing',
        );

        return $reprint;
    }

    // ── Manual retry ──────────────────────────────────────────────────────────

    /**
     * Reset a confirmed-failed job to pending for manual retry.
     * Only safe failures (exit codes 1–4: nothing was submitted) may be retried.
     * Uncertain jobs must be resolved by the owner first.
     */
    public function retryFailedJob(PrintJob $job): void
    {
        if ($job->status === 'uncertain') {
            throw new DomainException('Pekerjaan cetak yang tidak pasti (uncertain) harus diselesaikan oleh pemilik terlebih dahulu sebelum dapat dicoba ulang.');
        }

        if ($job->status !== 'failed') {
            throw new DomainException("Hanya pekerjaan cetak dengan status 'failed' yang dapat dicoba ulang. Status saat ini: {$job->status}");
        }

        // Fence: use DB::table to bypass model's immutability guard
        DB::table('print_jobs')
            ->where('id', $job->id)
            ->where('status', 'failed')
            ->update([
                'status' => 'pending',
                'claim_token' => null,
                'locked_at' => null,
                'last_error' => null,
                'updated_at' => now('Asia/Jakarta'),
            ]);
    }

    // ── Owner resolution of uncertain jobs ───────────────────────────────────

    /**
     * Owner manually resolves an uncertain job.
     *
     * @param  string  $resolution  'completed' or 'failed'
     */
    public function resolveUncertainJob(PrintJob $job, string $resolution, User $user): void
    {
        if ($job->status !== 'uncertain') {
            throw new DomainException("Hanya pekerjaan cetak dengan status 'uncertain' yang dapat diselesaikan secara manual. Status saat ini: {$job->status}");
        }

        if (! in_array($resolution, ['completed', 'failed'], true)) {
            throw new DomainException("Resolusi harus 'completed' atau 'failed'.");
        }

        $update = [
            'status' => $resolution,
            'claim_token' => null,
            'locked_at' => null,
            'updated_at' => now('Asia/Jakarta'),
        ];

        if ($resolution === 'completed') {
            $update['completed_at'] = now('Asia/Jakarta');
        }

        DB::table('print_jobs')
            ->where('id', $job->id)
            ->where('status', 'uncertain')
            ->update($update);

        ActivityLog::record(
            action: 'print.uncertain_resolved',
            description: "Pekerjaan cetak #{$job->id} diselesaikan secara manual sebagai '{$resolution}' oleh {$user->name}.",
            subject: $job,
            user: $user,
            module: 'printing',
        );
    }
}

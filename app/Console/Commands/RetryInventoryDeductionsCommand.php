<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\PaymentTransaction;
use App\Models\StockMovement;
use App\Services\Inventory\InventoryService;
use Illuminate\Console\Command;

class RetryInventoryDeductionsCommand extends Command
{
    protected $signature = 'inventory:retry-deductions {--apply : Apply failed inventory deductions instead of dry-run}';

    protected $description = 'List or retry failed automatic inventory deductions recorded in activity_logs.';

    public function handle(InventoryService $inventoryService): int
    {
        $failedLogs = ActivityLog::where('module', 'inventory')
            ->where('action', 'order_deduction_failed')
            ->orderBy('id')
            ->get();

        $txIds = $failedLogs
            ->map(static fn (ActivityLog $log): ?int => $log->properties['payment_transaction_id'] ?? $log->subject_id)
            ->filter(static fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($txIds === []) {
            $this->info('No failed inventory deductions found.');

            return self::SUCCESS;
        }

        $transactions = PaymentTransaction::whereIn('id', $txIds)
            ->orderBy('id')
            ->get()
            ->filter(static fn (PaymentTransaction $tx): bool => ! StockMovement::where('movement_type', StockMovement::TYPE_ORDER_DEDUCTION)
                ->where('idempotency_key', $tx->ulid)
                ->exists());

        if ($transactions->isEmpty()) {
            $this->info('No pending failed deductions remain.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        foreach ($transactions as $tx) {
            if (! $apply) {
                $this->line("[dry-run] Pending deduction: {$tx->payment_transaction_number} (order #{$tx->order_id}, ulid={$tx->ulid})");

                continue;
            }

            try {
                $inventoryService->deductForPayment($tx);
                $this->info("[applied] Deducted inventory for {$tx->payment_transaction_number} (order #{$tx->order_id}).");
            } catch (\Throwable $e) {
                $this->error("[failed] {$tx->payment_transaction_number}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}

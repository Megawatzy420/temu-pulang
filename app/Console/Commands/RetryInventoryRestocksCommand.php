<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use App\Models\RefundTransaction;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\InventoryService;
use Illuminate\Console\Command;

class RetryInventoryRestocksCommand extends Command
{
    protected $signature = 'inventory:retry-restocks {--apply : Apply failed refund restocks instead of dry-run}';

    protected $description = 'List or retry failed automatic refund restocks recorded in activity_logs.';

    public function handle(InventoryService $inventoryService): int
    {
        $failedLogs = ActivityLog::where('module', 'inventory')
            ->where('action', 'refund_restock_failed')
            ->orderBy('id')
            ->get();

        $refundIds = $failedLogs
            ->map(static fn (ActivityLog $log): ?int => $log->properties['refund_transaction_id'] ?? $log->subject_id)
            ->filter(static fn (mixed $id): bool => is_int($id) || ctype_digit((string) $id))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($refundIds === []) {
            $this->info('No failed inventory restocks found.');

            return self::SUCCESS;
        }

        $refunds = RefundTransaction::with('authorizedByUser')
            ->whereIn('id', $refundIds)
            ->orderBy('id')
            ->get()
            ->filter(static fn (RefundTransaction $refund): bool => ! StockMovement::where('movement_type', StockMovement::TYPE_REFUND_CREDIT)
                ->where('idempotency_key', $refund->ulid)
                ->exists());

        if ($refunds->isEmpty()) {
            $this->info('No pending failed restocks remain.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');

        foreach ($refunds as $refund) {
            if (! $apply) {
                $this->line("[dry-run] Pending restock: {$refund->refund_transaction_number} (order #{$refund->order_id}, ulid={$refund->ulid})");

                continue;
            }

            try {
                $actor = $refund->authorizedByUser ?? User::findOrFail($refund->authorized_by_user_id);
                $result = $inventoryService->restockForRefund($refund, true, $actor);
                $this->info("[applied] Restock ({$result}) for {$refund->refund_transaction_number} (order #{$refund->order_id}).");
            } catch (\Throwable $e) {
                $this->error("[failed] {$refund->refund_transaction_number}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}

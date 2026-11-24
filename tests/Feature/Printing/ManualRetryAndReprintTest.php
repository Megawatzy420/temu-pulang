<?php

namespace Tests\Feature\Printing;

use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PrintService;
use App\Services\ShiftService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ManualRetryAndReprintTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $cashier;

    protected Shift $shift;

    protected PrintService $printService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->owner = User::where('email', 'owner@temupulang.local')->firstOrFail();
        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Buka shift'
        );

        $this->printService = app(PrintService::class);
    }

    protected function createJob(string $status = 'pending', array $attributes = []): PrintJob
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();
        $order = app(OrderService::class)->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]
        );

        return PrintJob::create(array_merge([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80 Printer',
            'paper_width' => 80,
            'is_reprint' => false,
            'status' => $status,
            'attempts' => 1,
            'max_attempts' => 3,
        ], $attributes));
    }

    // ── Reprints ──────────────────────────────────────────────────────────────

    public function test_queue_reprint_creates_distinct_job_with_watermark_flag(): void
    {
        $original = $this->createJob('completed', [
            'completed_at' => now('Asia/Jakarta'),
        ]);

        $reprint = $this->printService->queueReprint($original, $this->cashier);

        // New distinct record
        $this->assertNotSame($original->id, $reprint->id);
        $this->assertTrue($reprint->is_reprint);
        $this->assertSame($original->id, $reprint->original_print_job_id);
        $this->assertSame($original->order_id, $reprint->order_id);
        $this->assertSame('pending', $reprint->status);
        $this->assertSame(0, $reprint->attempts);

        // Original record is completely unchanged
        $originalFresh = $original->fresh();
        $this->assertSame('completed', $originalFresh->status);
        $this->assertFalse($originalFresh->is_reprint);

        // Activity log recorded
        $this->assertDatabaseHas('activity_logs', [
            'action' => 'print.reprint_queued',
            'module' => 'printing',
            'user_id' => $this->cashier->id,
        ]);
    }

    // ── Manual retry ──────────────────────────────────────────────────────────

    public function test_retry_failed_job_resets_safe_failure_to_pending(): void
    {
        $failedJob = $this->createJob('failed', [
            'claim_token' => 'old-token',
            'locked_at' => now()->subMinutes(5),
            'last_error' => 'Helper exited with code 3 (OpenPrinter failed)',
        ]);

        $this->printService->retryFailedJob($failedJob);

        $fresh = $failedJob->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->claim_token);
        $this->assertNull($fresh->locked_at);
        $this->assertNull($fresh->last_error);
    }

    public function test_cannot_retry_uncertain_job_directly(): void
    {
        $uncertainJob = $this->createJob('uncertain', [
            'last_error' => 'Helper exited with code 5 (partial write)',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Pekerjaan cetak yang tidak pasti (uncertain) harus diselesaikan oleh pemilik');

        $this->printService->retryFailedJob($uncertainJob);
    }

    public function test_cannot_retry_completed_job(): void
    {
        $completedJob = $this->createJob('completed');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Hanya pekerjaan cetak dengan status 'failed' yang dapat dicoba ulang.");

        $this->printService->retryFailedJob($completedJob);
    }

    // ── Owner resolution ──────────────────────────────────────────────────────

    public function test_owner_can_resolve_uncertain_job_as_completed(): void
    {
        $uncertainJob = $this->createJob('uncertain', [
            'last_error' => 'Helper timeout',
        ]);

        $this->printService->resolveUncertainJob($uncertainJob, 'completed', $this->owner);

        $fresh = $uncertainJob->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertNull($fresh->claim_token);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'print.uncertain_resolved',
            'module' => 'printing',
            'user_id' => $this->owner->id,
        ]);
    }

    public function test_owner_can_resolve_uncertain_job_as_failed_and_then_retry(): void
    {
        $uncertainJob = $this->createJob('uncertain', [
            'last_error' => 'Printer out of paper mid-stream',
        ]);

        // 1. Owner resolves as failed
        $this->printService->resolveUncertainJob($uncertainJob, 'failed', $this->owner);

        $fresh = $uncertainJob->fresh();
        $this->assertSame('failed', $fresh->status);

        // 2. Now safe manual retry is permitted
        $this->printService->retryFailedJob($fresh);

        $retried = $uncertainJob->fresh();
        $this->assertSame('pending', $retried->status);
        $this->assertNull($retried->last_error);
    }

    public function test_resolving_non_uncertain_job_throws_exception(): void
    {
        $pendingJob = $this->createJob('pending');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Hanya pekerjaan cetak dengan status 'uncertain' yang dapat diselesaikan secara manual.");

        $this->printService->resolveUncertainJob($pendingJob, 'completed', $this->owner);
    }
}

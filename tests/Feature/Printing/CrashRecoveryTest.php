<?php

namespace Tests\Feature\Printing;

use App\Console\Commands\PrintWorkerCommand;
use App\Models\Order;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\Shift;
use App\Models\User;
use App\Services\OrderService;
use App\Services\Printing\KitchenTicketRendererService;
use App\Services\Printing\ReceiptRendererService;
use App\Services\ShiftService;
use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;

class CrashRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected User $cashier;

    protected Shift $shift;

    protected PrintWorkerCommand $workerCommand;

    protected ReflectionClass $reflector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        $this->cashier = User::where('email', 'cashier@temupulang.local')->firstOrFail();
        $this->shift = app(ShiftService::class)->openShift(
            user: $this->cashier,
            openingCash: 100000,
            openingNote: 'Buka shift'
        );

        $this->workerCommand = new PrintWorkerCommand(
            app(ReceiptRendererService::class),
            app(KitchenTicketRendererService::class)
        );
        $this->workerCommand->setOutput(new OutputStyle(new ArrayInput([]), new NullOutput));

        $this->reflector = new ReflectionClass(PrintWorkerCommand::class);
    }

    protected function createOrder(): Order
    {
        $product = Product::where('is_sold_out', false)->firstOrFail();

        return app(OrderService::class)->createOrder(
            cashier: $this->cashier,
            shift: $this->shift,
            orderPayload: [
                'order_type' => Order::TYPE_DINE_IN,
                'items' => [
                    ['product_id' => $product->id, 'quantity' => 1],
                ],
            ]
        );
    }

    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $m = $this->reflector->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($this->workerCommand, $args);
    }

    public function test_stale_processing_job_is_recovered_as_uncertain(): void
    {
        $order = $this->createOrder();

        // Create a job that was left in processing state 120 seconds ago (simulating worker killed)
        $job = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80 Printer',
            'paper_width' => 80,
            'is_reprint' => false,
            'status' => 'processing',
            'attempts' => 1,
            'max_attempts' => 3,
            'claim_token' => (string) Str::uuid(),
            'locked_at' => now()->subSeconds(120),
        ]);

        // Run recovery with 60-second threshold
        $this->invokeMethod('runStartupRecovery', [60]);

        $fresh = $job->fresh();
        $this->assertSame('uncertain', $fresh->status);
        $this->assertNull($fresh->claim_token);
        $this->assertNull($fresh->locked_at);
        $this->assertStringContainsString('Worker crash recovery', $fresh->last_error);

        // Crucial: It must NEVER be automatically reprinted / put back in pending
        $this->assertNotSame('pending', $fresh->status);
    }

    public function test_active_pending_jobs_are_not_affected_by_recovery(): void
    {
        $order = $this->createOrder();

        $pendingJob = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80 Printer',
            'paper_width' => 80,
            'is_reprint' => false,
            'status' => 'pending',
            'attempts' => 0,
            'max_attempts' => 3,
        ]);

        $this->invokeMethod('runStartupRecovery', [60]);

        $fresh = $pendingJob->fresh();
        $this->assertSame('pending', $fresh->status);
        $this->assertNull($fresh->claim_token);
    }

    public function test_multiple_stale_jobs_are_all_recovered_as_uncertain(): void
    {
        $order = $this->createOrder();

        $job1 = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'customer_receipt',
            'printer_destination' => 'POS80 Printer',
            'status' => 'processing',
            'attempts' => 1,
            'claim_token' => (string) Str::uuid(),
            'locked_at' => now()->subSeconds(90),
        ]);

        $job2 = PrintJob::create([
            'ulid' => (string) Str::ulid(),
            'order_id' => $order->id,
            'job_type' => 'kitchen_ticket',
            'printer_destination' => 'POS80 Printer',
            'status' => 'processing',
            'attempts' => 1,
            'claim_token' => (string) Str::uuid(),
            'locked_at' => now()->subSeconds(75),
        ]);

        $this->invokeMethod('runStartupRecovery', [60]);

        $this->assertSame('uncertain', $job1->fresh()->status);
        $this->assertSame('uncertain', $job2->fresh()->status);
    }
}
